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
import crypto from 'node:crypto'
import path from 'node:path'
import fs from 'node:fs'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'

import {
  configBitnob, createInvoice, getInvoiceStatus,
  payInvoice, verifyWebhook, signerWebhook, onMockPayment, onEchange, abreger,
} from '../src/lib/bitnob.js'
import { factures, eventsVus, journal, noter, echanges, noterEchange, dernierWebhook } from './registre.js'

dotenv.config()

// Les appels à Bitnob tracés par le module arrivent dans le fil
onEchange(noterEchange)

// ════════════════════════════════════════════════════════════════
//  PROTECTION — avant de mettre en ligne avec de vraies clés
//  • ADMIN_TOKEN : si défini, le payout, le fil, le journal et les
//    outils de démo exigent l'en-tête x-admin-token.
//  • Avec de vraies clés SANS ADMIN_TOKEN, le payout est refusé :
//    sinon n'importe qui pourrait payer avec ton solde Bitnob.
//  • DEMO_TOOLS=false coupe le fil des échanges et le rejeu.
//  Le webhook reste public (Bitnob doit pouvoir l'appeler) : il est
//  protégé par sa signature HMAC.
// ════════════════════════════════════════════════════════════════
const jetonAdmin = () => process.env.ADMIN_TOKEN || ''
const outilsDemo = () => String(process.env.DEMO_TOOLS ?? 'true').toLowerCase() !== 'false'

function exigerAdmin(req, res, next) {
  const attendu = jetonAdmin()
  if (!attendu) return next()
  // On compare les empreintes : même longueur, comparaison en temps constant
  const empreinte = (v) => crypto.createHash('sha256').update(String(v)).digest()
  if (crypto.timingSafeEqual(empreinte(req.get('x-admin-token') || ''), empreinte(attendu))) return next()
  res.status(401).json({ erreur: 'code admin requis ou invalide', admin: true })
}
function exigerOutilsDemo(_req, res, next) {
  if (outilsDemo()) return next()
  res.status(404).json({ erreur: 'outils de démo désactivés (DEMO_TOOLS=false)' })
}

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
//  `source` : 'http' (vrai POST), 'simulation' (webhook fabriqué par
//  le mode simulation) ou 'rejeu' (démo « rejouer le webhook »).
export function traiterWebhookBrut(corpsBrut, enTetes, { source = 'http' } = {}) {
  const { code, reponse, verifs } = appliquerWebhook(corpsBrut, enTetes)

  // Le fil : la requête reçue, puis les vérifications du serveur
  let corps
  try { corps = abreger(JSON.parse(corpsBrut)) } catch { corps = abreger(String(corpsBrut ?? '')) }
  const signature = enTetes['x-bitnob-signature']
  noterEchange({ cle: source === 'rejeu' ? 'webhook.rejeu' : code === 401 ? 'webhook.refuse' : 'webhook',
  de: 'bitnob', vers: 'serveur', type: 'req', simule: source === 'simulation', ko: code >= 400,
  http: { methode: 'POST', url: '/api/webhook/bitnob', statut: code },
  entetes: [['x-bitnob-signature', signature ? abreger(String(signature)) : '(absente)']], corps })
  noterEchange({ cle: 'webhook.verif', de: 'serveur', vers: 'serveur', type: 'soi', ko: code >= 400, verifs })

  // On garde le dernier webhook authentique pour la démo du rejeu
  if (code === 200 && source !== 'rejeu') Object.assign(dernierWebhook, { corps: corpsBrut, signature })
  return { code, reponse }
}

function appliquerWebhook(corpsBrut, enTetes) {
  const verification = verifyWebhook({ headers: enTetes, rawBody: corpsBrut })
  if (!verification.ok) {
  noter('webhook', 'Webhook REJETÉ', verification.raison, false)
  return { code: 401, reponse: { erreur: 'signature invalide' },
  verifs: [[false, 'Signature HMAC-SHA512 du corps brut', `${verification.raison} : rien n'est crédité`]] }
  }
  const { event, eventId, data } = verification.evenement
  const sigOk = [true, 'Signature HMAC-SHA512 valide', 'comparaison en temps constant']

  // 1) Déduplication par event_id (Bitnob renvoie jusqu'à 3 fois en retry)
  if (eventId && eventsVus.has(eventId)) {
  noter('webhook', `Webhook ${event}`, 'déjà vu (retry) — ignoré', true)
  return { code: 200, reponse: { ok: true, dejaVu: true },
  verifs: [sigOk, [false, `event_id ${eventId} déjà traité`, 'retry : réponse 200, aucun crédit']] }
  }
  if (eventId) eventsVus.add(eventId)
  const eventOk = [true, `event_id ${eventId || '(aucun)'} jamais vu`]

  // 2) On retrouve notre facture par id ou reference
  const facture = [...factures.values()].find(
  (f) => f.id === data.id || f.reference === data.reference,
  )
  if (!facture) {
  noter('webhook', `Webhook ${event}`, 'facture inconnue — ignoré', true)
  return { code: 200, reponse: { ok: true, ignore: 'facture inconnue' },
  verifs: [sigOk, eventOk, [false, 'Facture inconnue', 'réponse 200, rien à créditer']] }
  }

  // 3) Idempotence : cette facture a-t-elle déjà produit son effet ?
  if (facture.traitee) {
  noter('webhook', `Webhook ${event}`, 'facture déjà créditée — ignoré', true)
  return { code: 200, reponse: { ok: true, dejaTraitee: true },
  verifs: [sigOk, eventOk, [false, 'Facture déjà créditée', 'un paiement = un seul effet']] }
  }
  facture.traitee = true
  facture.status = 'paid'
  facture.preimage = data.preimage || facture.preimage
  facture.payeA = Date.now()

  noter('webhook', `Webhook ${event}`,
  `facture ${facture.id.slice(0, 8)} créditée — ${facture.montantSats} sats `, true)
  return { code: 200, reponse: { ok: true, applique: true },
  verifs: [sigOk, eventOk, [true, 'Facture créditée', `${facture.montantSats} sats, une seule fois`]] }
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
  traiterWebhookBrut(corps, { 'x-bitnob-signature': signerWebhook(corps) }, { source: 'simulation' })
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
  protege: !!jetonAdmin(),
  outilsDemo: outilsDemo(),
  payoutActif: cfg.mock || !!jetonAdmin(),
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
  noterEchange({ cle: 'facture.demande', de: 'nav', vers: 'serveur', type: 'req',
  http: { methode: 'POST', url: '/api/invoices' }, corps: abreger(req.body ?? {}) })
  if (!Number.isFinite(montant) || montant < 1 || montant > 20_000_000) {
  return res.status(400).json({ erreur: 'montant invalide (1 à 20 000 000 sats)' })
  }
  const facture = await createInvoice(Math.round(montant), { description })
  factures.set(facture.id, facture)
  noter('facture', 'Facture créée', `${facture.montantSats} sats — « ${description} »`, true)
  noterEchange({ cle: 'facture.reponse', de: 'serveur', vers: 'nav', type: 'rep', bolt11: facture.paymentRequest,
  http: { methode: 'POST', url: '/api/invoices', statut: 201 },
  corps: abreger({ id: facture.id, status: facture.status, montantSats: facture.montantSats, paymentRequest: facture.paymentRequest }) })
  res.status(201).json(facture)
  } catch (e) {
  console.error('POST /api/invoices :', e)
  noter('facture', 'Échec création facture', String(e.message || e), false)
  noterEchange({ cle: 'facture.reponse', de: 'serveur', vers: 'nav', type: 'rep', ko: true,
  http: { methode: 'POST', url: '/api/invoices', statut: 500 }, corps: { erreur: String(e.message || e) } })
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
  noterEchange({ cle: 'polling.confirme', de: 'serveur', vers: 'serveur', type: 'soi',
  verifs: [[true, 'Bitnob dit « paid » alors qu\'aucun webhook n\'est arrivé', 'filet de sécurité : crédité une seule fois']] })
  } else if (statut.status === 'expired' || statut.status === 'failed') {
  facture.status = statut.status
  }
  }
  // Le polling tourne chaque seconde : dans le fil, on ne montre que
  // le moment où le navigateur découvre que c'est payé.
  if (facture.status === 'paid' && !facture.vuPayeParNav) {
  facture.vuPayeParNav = true
  noterEchange({ cle: 'statut.demande', de: 'nav', vers: 'serveur', type: 'req', http: { methode: 'GET', url: `/api/invoices/${facture.id.slice(0, 8)}…` } })
  noterEchange({ cle: 'statut.reponse', de: 'serveur', vers: 'nav', type: 'rep',
  http: { methode: 'GET', url: `/api/invoices/${facture.id.slice(0, 8)}…`, statut: 200 },
  corps: abreger({ status: 'paid', preimage: facture.preimage }) })
  }
  res.json({
  id: facture.id, status: facture.status, montantSats: facture.montantSats,
  paymentHash: facture.paymentHash, preimage: facture.preimage, traitee: facture.traitee,
  })
  } catch (e) {
  console.error('GET /api/invoices/:id :', e)
  res.status(500).json({ erreur: String(e.message || e) })
  }
})

// ════════════════════════════════════════════════════════════════
//  POST /api/pay — payer une facture Lightning (payout sortant)
// ════════════════════════════════════════════════════════════════
app.post('/api/pay', exigerAdmin, async (req, res) => {
  if (!configBitnob().mock && !jetonAdmin()) {
    return res.status(403).json({ erreur: 'payout réel désactivé : définis ADMIN_TOKEN dans .env pour l\'autoriser' })
  }
  noterEchange({ cle: 'payout.demande', de: 'nav', vers: 'serveur', type: 'req',
  http: { methode: 'POST', url: '/api/pay' }, corps: abreger({ invoice: String(req.body?.invoice || '') }) })
  try {
  const resultat = await payInvoice(String(req.body?.invoice || ''), {})
  noter('payout', 'Payout envoyé',
  `${resultat.montantSats ?? '?'} sats · frais ${resultat.feeSats} · ${resultat.status}`, true)
  noterEchange({ cle: 'payout.reponse', de: 'serveur', vers: 'nav', type: 'rep',
  http: { methode: 'POST', url: '/api/pay', statut: 200 }, corps: abreger(resultat) })
  res.json(resultat)
  } catch (e) {
  console.error('POST /api/pay :', e)
  noter('payout', 'Échec du payout', String(e.message || e), false)
  noterEchange({ cle: 'payout.reponse', de: 'serveur', vers: 'nav', type: 'rep', ko: true,
  http: { methode: 'POST', url: '/api/pay', statut: 400 }, corps: { erreur: String(e.message || e) } })
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

// ════════════════════════════════════════════════════════════════
//  POST /api/demo/rejouer-webhook — démo de sécurité
//  Renvoie au serveur le DERNIER webhook authentique, octet pour
//  octet, signature comprise : exactement ce que fait un retry de
//  Bitnob (ou un attaquant qui aurait intercepté la requête).
// ════════════════════════════════════════════════════════════════
app.post('/api/demo/rejouer-webhook', exigerOutilsDemo, exigerAdmin, (_req, res) => {
  if (!dernierWebhook.corps) return res.status(409).json({ erreur: 'aucun webhook reçu pour l\'instant' })
  const { code, reponse } = traiterWebhookBrut(dernierWebhook.corps,
    { 'x-bitnob-signature': dernierWebhook.signature }, { source: 'rejeu' })
  res.status(code).json(reponse)
})

// ── Journal d'activité (l'UI le rafraîchit toutes les 1,5 s) ──
app.get('/api/journal', exigerAdmin, (_req, res) => res.json({ journal }))

// ── Le fil des échanges (interface Banc), depuis le n° donné ──
app.get('/api/echanges', exigerOutilsDemo, exigerAdmin, (req, res) => {
  const depuis = Number(req.query.depuis) || 0
  res.json({ echanges: echanges.filter((e) => e.n > depuis) })
})

// ── Fichiers statiques de l'app Vue (production) ───────────────
const dossierDist = path.resolve(__dirname, '../dist')
if (fs.existsSync(dossierDist)) {
  app.use(express.static(dossierDist))
  app.get(/^(?!\/api).*/, (_req, res) => res.sendFile(path.join(dossierDist, 'index.html')))
}

export default app
