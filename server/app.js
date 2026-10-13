// ════════════════════════════════════════════════════════════════
//  SERVEUR EXPRESS — 4 routes API, c'est tout :
//  POST /api/invoices  créer une facture Lightning
//  GET  /api/invoices/:id  son statut (pending → paid)
//  POST /api/pay  payer une facture (payout)
//  POST /api/webhook/bitnob → Bitnob notifie un paiement
//  GET  /api/journal  l'activité en direct (pour l'UI)
//  En production il sert aussi les fichiers compilés de dist/.
// ════════════════════════════════════════════════════════════════

import express from 'express'
import path from 'node:path'
import fs from 'node:fs'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'

import {
  configBitnob, createInvoice, getInvoiceStatus,
  payInvoice, verifyWebhook, signerWebhook, onMockPayment,
} from '../src/lib/bitnob.js'
import { factures, eventsVus, journal, noter, expirerFactures } from './registre.js'

dotenv.config()

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const app = express()

//  Concept : il faut le CORPS BRUT (texte exact) pour vérifier la
//  signature HMAC du webhook. express.json() transformerait le corps
//  en objet : on le garde donc de côté dans req.rawBody.
app.use(express.json({
  limit: '32kb',
  verify: (req, _res, buf) => { req.rawBody = buf.toString('utf8') },
}))

// ════════════════════════════════════════════════════════════════
//  Traitement d'un webhook — LE point d'entrée des paiements.
//  3 filets de sécurité : signature vérifiée, event_id dédupliqué,
//  idempotence par facture (un paiement = un seul effet).
//  Cette fonction est appelée par la route HTTP… et par le mode
//  simulation, qui lui envoie un webhook signé "maison".
// ════════════════════════════════════════════════════════════════
export function traiterWebhookBrut(corpsBrut, enTetes) {
  const verification = verifyWebhook({ headers: enTetes, rawBody: corpsBrut })
  if (!verification.ok) {
  noter('webhook', 'Webhook REJETÉ', verification.raison, false)
  return { code: 401, reponse: { erreur: 'signature invalide' } }
  }
  const { event, eventId, data } = verification.evenement

  // 1) Déduplication par event_id (Bitnob renvoie jusqu'à 3 fois en retry)
  if (eventId && eventsVus.has(eventId)) {
  noter('webhook', `Webhook ${event}`, 'déjà vu (retry) — ignoré', true)
  return { code: 200, reponse: { ok: true, dejaVu: true } }
  }
  if (eventId) eventsVus.add(eventId)

  // 2) On retrouve notre facture par id ou reference
  const facture = [...factures.values()].find(
  (f) => f.id === data.id || f.reference === data.reference,
  )
  if (!facture) {
  noter('webhook', `Webhook ${event}`, 'facture inconnue — ignoré', true)
  return { code: 200, reponse: { ok: true, ignore: 'facture inconnue' } }
  }

  // 3) Idempotence : cette facture a-t-elle déjà produit son effet ?
  if (facture.traitee) {
  noter('webhook', `Webhook ${event}`, 'facture déjà créditée — ignoré', true)
  return { code: 200, reponse: { ok: true, dejaTraitee: true } }
  }
  facture.traitee = true
  facture.status = 'paid'
  facture.preimage = data.preimage || facture.preimage
  facture.payeA = Date.now()

  noter('webhook', `Webhook ${event}`,
  `facture ${facture.id.slice(0, 8)} créditée — ${facture.montantSats} sats `, true)
  return { code: 200, reponse: { ok: true, applique: true } }
}

// Mode simulation : quand une fausse facture est "payée", on fabrique
// le webhook que Bitnob aurait envoyé, signé avec le même HMAC, et on
// le passe par le MÊME chemin de traitement que webhook réel.
onMockPayment((f) => {
  try {
  const corps = JSON.stringify({
  event: 'btc.lightning.received.success',
  event_id: `mock-${f.id}`,
  data: { id: f.id, reference: f.reference, payment_hash: f.paymentHash, status: 'paid', preimage: f.preimage },
  })
  traiterWebhookBrut(corps, { 'x-bitnob-signature': signerWebhook(corps) })
  } catch (e) {
  console.error('webhook simulé :', e)
  }
})

// ── Infos générales (mode, URL du webhook à configurer) ────────
app.get('/api/info', (_req, res) => {
  const cfg = configBitnob()
  const pub = (process.env.PUBLIC_URL || '').replace(/\/$/, '')
  res.json({
  mock: cfg.mock,
  baseUrl: cfg.baseUrl,
  webhookUrl: pub ? `${pub}/api/webhook/bitnob` : '/api/webhook/bitnob',
  webhooksConfigures: !!(cfg.webhookSecret || cfg.mock),
  })
})

// ════════════════════════════════════════════════════════════════
//  POST /api/invoices — créer une facture Lightning
//  Concept : la facture ne bouge pas d'ici tant qu'elle n'est pas
//  payée sur le réseau — on l'affiche (QR/BOLT11) et on attend.
// ════════════════════════════════════════════════════════════════
app.post('/api/invoices', async (req, res) => {
  try {
  const montant = Number(req.body?.amount)
  const description = String(req.body?.description || 'Démo Bitnob').slice(0, 120)
  if (!Number.isFinite(montant) || montant < 1 || montant > 20_000_000) {
  return res.status(400).json({ erreur: 'montant invalide (1 à 20 000 000 sats)' })
  }
  const facture = await createInvoice(Math.round(montant), { description })
  factures.set(facture.id, facture)
  noter('facture', 'Facture créée', `${facture.montantSats} sats — « ${description} »`, true)
  res.status(201).json(facture)
  } catch (e) {
  console.error('POST /api/invoices :', e)
  noter('facture', 'Échec création facture', String(e.message || e), false)
  res.status(500).json({ erreur: String(e.message || e) })
  }
})

// ════════════════════════════════════════════════════════════════
//  GET /api/invoices/:id — statut d'une facture (polling UI)
//  Concept : "filet de sécurité". Même si le webhook est perdu,
//  cette interrogation peut confirmer le paiement — toujours
//  côté serveur, jamais le navigateur ne déclare un paiement.
// ════════════════════════════════════════════════════════════════
app.get('/api/invoices/:id', async (req, res) => {
  try {
  const facture = factures.get(req.params.id)
  if (!facture) return res.status(404).json({ erreur: 'facture inconnue' })

  if (facture.status === 'pending') {
  const statut = await getInvoiceStatus(facture.id)
  if (statut.status === 'paid' && !facture.traitee) {
  // Le webhook s'est perdu ? On crédit quand même, une seule fois.
  facture.traitee = true
  facture.status = 'paid'
  facture.preimage = statut.preimage || facture.preimage
  noter('facture', 'Paiement confirmé (polling)', `facture ${facture.id.slice(0, 8)} `, true)
  } else if (statut.status === 'expired' || statut.status === 'failed') {
  facture.status = statut.status
  }
  }
  res.json({
  id: facture.id, status: facture.status, montantSats: facture.montantSats,
  preimage: facture.preimage, traitee: facture.traitee,
  })
  } catch (e) {
  console.error('GET /api/invoices/:id :', e)
  res.status(500).json({ erreur: String(e.message || e) })
  }
})

// ════════════════════════════════════════════════════════════════
//  POST /api/pay — payer une facture Lightning (payout sortant)
// ════════════════════════════════════════════════════════════════
app.post('/api/pay', async (req, res) => {
  try {
  const resultat = await payInvoice(String(req.body?.invoice || ''), {})
  noter('payout', 'Payout envoyé',
  `${resultat.montantSats ?? '?'} sats · frais ${resultat.feeSats} · ${resultat.status}`, true)
  res.json(resultat)
  } catch (e) {
  console.error('POST /api/pay :', e)
  noter('payout', 'Échec du payout', String(e.message || e), false)
  res.status(400).json({ erreur: String(e.message || e) })
  }
})

// ════════════════════════════════════════════════════════════════
//  POST /api/webhook/bitnob — l'URL à donner à Bitnob
//  On répond 200 vite fait (sinon Bitnob entre en retry), la
//  vérification de signature est faite dans traiterWebhookBrut.
// ════════════════════════════════════════════════════════════════
app.post('/api/webhook/bitnob', (req, res) => {
  const { code, reponse } = traiterWebhookBrut(req.rawBody, req.headers)
  res.status(code).json(reponse)
})

// ── Journal d'activité (l'UI le rafraîchit toutes les 1,5 s) ──
app.get('/api/journal', (_req, res) => res.json({ journal }))

// ── Fichiers statiques de l'app Vue (production) ───────────────
const dossierDist = path.resolve(__dirname, '../dist')
if (fs.existsSync(dossierDist)) {
  app.use(express.static(dossierDist))
  app.get(/^(?!\/api).*/, (_req, res) => res.sendFile(path.join(dossierDist, 'index.html')))
}

export default app
