// ════════════════════════════════════════════════════════════════
//  MODULE BITNOB — toute l'intégration paiement vit ICI, et
//  nulle part ailleurs. Le navigateur n'appelle JAMAIS Bitnob.
//
//  API utilisée (documentation officielle bitnob.dev, lue avant
//  d'écrire ce fichier) :
//  • Base URL  : https://api.bitnob.com  (sandbox = production,
//  seule la clé change — voir README)
//  • Auth HMAC  : en-têtes X-Auth-Client, X-Auth-Timestamp,
//  X-Auth-Nonce, X-Auth-Signature
//  message = CLIENT_ID:TIMESTAMP:NONCE:PAYLOAD
//  signé en HMAC-SHA256 hex avec la SECRET KEY
//  • Créer  : POST /api/lightning/invoices
//  body { reference, amount(sats), description, expiry }
//  201 { id, payment_hash, request(BOLT11),… }
//  • Statut  : GET  /api/lightning/invoices/:id
//  data.status ∈ pending | paid | expired | failed
//  • Payer  : POST /api/lightning/pay  (envoyer des sats à une
//  facture BOLT11 — corps à confirmer, voir README)
//  • Webhooks  : en-tête x-bitnob-signature = HMAC-SHA512 du CORPS
//  BRUT avec ton secret de webhook ; event_id sert à
//  dédupliquer (ex. d'événement : btc.lightning.received.success)
//
//  Concept : "sandbox". Bitnob fournit un environnement de test où
//  les vrais sats ne circulent pas : mêmes endpoints, clés de test.
//  Pour le workshop, MOCK_PAYMENTS=true va plus loin : aucune clé,
//  aucun argent — le module fabrique de fausses factures, les "paie"
//  tout seul après 2 s, et simule le webhook signé de Bitnob.
//
//  SÉCURITÉ : ce module lit BITNOB_SECRET_KEY. Il ne doit JAMAIS
//  être importé depuis un composant Vue (le garde-fou ci-dessous le
//  rappelle). Aucun secret ne part vers le navigateur.
// ════════════════════════════════════════════════════════════════

import crypto from 'node:crypto'

// Garde-fou pédagogique : ce fichier est serveur UNIQUEMENT.
if (typeof window !== 'undefined') {
  throw new Error(' src/lib/bitnob.js ne doit jamais être chargé dans le navigateur !')
}

// ── Lecture (tardive) de la configuration ──────────────────────
//  on lit process.env au moment de l'appel, pas au chargement :
//  ça évite les mauvaises surprises avec dotenv / les serverless.
export function configBitnob() {
  return {
  baseUrl: (process.env.BITNOB_BASE_URL || 'https://api.bitnob.com').replace(/\/$/, ''),
  clientId: process.env.BITNOB_CLIENT_ID || '',
  secretKey: process.env.BITNOB_SECRET_KEY || '',
  webhookSecret: process.env.BITNOB_WEBHOOK_SECRET || '',
  mock: String(process.env.MOCK_PAYMENTS || 'true').toLowerCase() === 'true',
  mockDelaiMs: Number(process.env.MOCK_PAYMENT_DELAY_MS || 2000),
  expiryS: Number(process.env.INVOICE_EXPIRY_S || 600),
  }
}

// ── Signature HMAC des requêtes sortantes (auth Bitnob) ────────
//  Concept : HMAC = "prouve que je détienne la clé secrète, sans
//  jamais l'envoyer". Le nonce (aléatoire) empêche qu'une requête
//  capturée soit rejouée telle quelle.
function enTetesAuth(payload) {
  const { clientId, secretKey } = configBitnob()
  if (!clientId || !secretKey) {
  throw new Error('BITNOB_CLIENT_ID / BITNOB_SECRET_KEY manquants dans .env')
  }
  const timestamp = Math.floor(Date.now() / 1000).toString()
  const nonce = crypto.randomBytes(16).toString('hex')
  const message = [clientId, timestamp, nonce, payload].join(':')
  const signature = crypto.createHmac('sha256', secretKey).update(message).digest('hex')
  return {
  'Content-Type': 'application/json',
  'X-Auth-Client': clientId,
  'X-Auth-Timestamp': timestamp,
  'X-Auth-Nonce': nonce,
  'X-Auth-Signature': signature,
  }
}

// Petit client HTTP vers l'API Bitnob (timeout 10 s).
async function appelerBitnob(methode, chemin, corps = null) {
  const { baseUrl } = configBitnob()
  const brut = corps ? JSON.stringify(corps) : ''
  const reponse = await fetch(`${baseUrl}${chemin}`, {
  method: methode,
  headers: enTetesAuth(brut),
  body: brut || undefined,
  signal: AbortSignal.timeout(10_000),
  })
  const data = await reponse.json().catch(() => ({}))
  if (!reponse.ok) {
  throw new Error(`Bitnob ${methode} ${chemin} → ${reponse.status} ${JSON.stringify(data)}`)
  }
  return data
}

// ── Base de données MINIATURE (mode simulation) ────────────────
const facturesMock = new Map()
let handlerPaiementMock = null

// Le serveur s'abonne ici : quand une facture mock est "payée", il
// fabrique un webhook signé, comme Bitnob l'aurait fait pour de vrai.
export function onMockPayment(handler) {
  handlerPaiementMock = handler
}

const hexAlea = (n) => crypto.randomBytes(n).toString('hex')

// ════════════════════════════════════════════════════════════════
//  createInvoice(amountSats, metadata)
//  Concept : "facture Lightning" = une demande de paiement signée
//  que n'importe quel portefeuille Lightning peut payer. Elle porte
//  un hash, un montant, une durée de vie, et se transforme en QR.
// ════════════════════════════════════════════════════════════════
export async function createInvoice(amountSats, metadata = {}) {
  const cfg = configBitnob()

  // ── MODE SIMULATION : aucune clé, aucun argent réel ──────────
  if (cfg.mock) {
  const id = crypto.randomUUID()
  const reference = `demo-${id.slice(0, 8)}`
  const facture = {
  id,
  reference,
  paymentHash: hexAlea(32),
  montantSats: amountSats,
  description: metadata.description || 'Démo Bitnob',
  paymentRequest: `lnbcrt${amountSats}n1demobitnob${id.replace(/-/g, '')}`,
  status: 'pending',
  preimage: '',
  expiresAt: Date.now() + cfg.expiryS * 1000,
  traitee: false,
  mock: true,
  }
  facturesMock.set(id, facture)

  // ⏱ Auto-paiement factice après MOCK_PAYMENT_DELAY_MS (2 s) :
  //  le serveur recevra ensuite un webhook signé, comme en vrai.
  setTimeout(() => {
  const f = facturesMock.get(id)
  if (!f || f.status !== 'pending') return
  f.status = 'paid'
  f.preimage = hexAlea(32)
  handlerPaiementMock?.(f)
  }, cfg.mockDelaiMs)

  return { ...facture }
  }

  // ── VRAI APPEL BITNOB (sandbox ou production) ────────────────
  //  Concept : "reference" = clé d'idempotence. Si on renvoie la
  //  même reference, Bitnob renvoie la facture d'origine au lieu
  //  d'en créer un doublon.
  const reference = `demo-${crypto.randomUUID().slice(0, 8)}`
  const corps = {
  reference,
  amount: amountSats, // les montants sont en sats chez Bitnob
  description: metadata.description || 'Démo Bitnob',
  expiry: cfg.expiryS, // secondes
  }
  const reponse = await appelerBitnob('POST', '/api/lightning/invoices', corps)
  const f = reponse.data ?? reponse
  return {
  id: f.id,
  reference: f.reference || reference,
  paymentHash: f.payment_hash,
  montantSats: amountSats,
  description: f.description,
  paymentRequest: f.request, // la chaîne BOLT11 à mettre dans le QR
  status: f.status || 'pending',
  preimage: f.preimage || '',
  expiresAt: Date.parse(f.expires_at) || Date.now() + cfg.expiryS * 1000,
  traitee: false,
  mock: false,
  }
}

// ════════════════════════════════════════════════════════════════
//  getInvoiceStatus(id)
//  Concept : le webhook est le canal principal, mais on garde ce
//  point d'interrogation comme FILET DE SÉCURITÉ : si un webhook se
//  perd, on peut revérifier soi-même l'état de la facture.
//  Retourne { status, paymentHash?, preimage? }.
// ════════════════════════════════════════════════════════════════
export async function getInvoiceStatus(id) {
  const cfg = configBitnob()
  if (cfg.mock) {
  const f = facturesMock.get(id)
  if (!f) return { status: 'unknown' }
  // Gestion de l'expiration : une facture non payée finit sa vie.
  if (f.status === 'pending' && Date.now() > f.expiresAt) f.status = 'expired'
  return { status: f.status, paymentHash: f.paymentHash, preimage: f.preimage }
  }
  const reponse = await appelerBitnob('GET', `/api/lightning/invoices/${encodeURIComponent(id)}`)
  const f = reponse.data ?? reponse
  //  "paid" accompagné de la preimage = preuve cryptographique
  //  que le paiement a été réglé sur le réseau Lightning.
  return { status: f.status, paymentHash: f.payment_hash, preimage: f.preimage }
}

// ════════════════════════════════════════════════════════════════
//  payInvoice(bolt11, { reference })
//  Concept : "payout" = ENVOYER des sats à une facture Lightning
//  créée par quelqu'un d'autre (on paie son QR). C'est le chemin
//  sortant de l'argent, l'inverse de createInvoice.
//  Le corps exact de POST /api/lightning/pay n'est pas détaillé
//  dans la partie de la doc que j'ai pu lire : on envoie { invoice,
//  reference } (champs les plus courants) — à confirmer dans le
//  playground Bitnob, note dans le README.
// ════════════════════════════════════════════════════════════════
export async function payInvoice(bolt11, { reference } = {}) {
  const cfg = configBitnob()
  if (typeof bolt11 !== 'string' || !/^ln(bc|tb|bcrt)/i.test(bolt11.trim())) {
  throw new Error('Ce n\'est pas une facture Lightning (BOLT11 commence par lnbc / lntb / lnbcrt)')
  }

  if (cfg.mock) {
  await new Promise((r) => setTimeout(r, 1200)) // on laisse le temps de voir
  // Montant "extrait" de la facture pour l'affichage (lnbc1500n1… → 150 sats)
  const m = bolt11.match(/^ln(?:bc|tb|bcrt)(\d+)([munp])?/i)
  let montantSats = null
  if (m) {
  const n = Number(m[1])
  const unit = { m: 1e5, '': 1e2, u: 1e-1, p: 1e-4 }[String(m[2] ?? '').toLowerCase()] ?? 1e2
  montantSats = Math.round(n * unit)
  }
  return {
  id: `pay-${crypto.randomUUID().slice(0, 8)}`,
  status: 'success',
  feeSats: Math.floor(Math.random() * 3),
  montantSats,
  reference: reference || 'demo-payout',
  mock: true,
  }
  }

  const ref = reference || `payout-${crypto.randomUUID().slice(0, 8)}`
  const reponse = await appelerBitnob('POST', '/api/lightning/pay', {
  invoice: bolt11.trim(),
  reference: ref,
  })
  const f = reponse.data ?? reponse
  return {
  id: f.id,
  status: f.status || 'success',
  feeSats: Number(f.fee_sat ?? f.fees ?? 0),
  montantSats: f.amount_sat ? Number(f.amount_sat) : null,
  reference: f.reference || ref,
  mock: false,
  }
}

// ════════════════════════════════════════════════════════════════
//  signerWebhook(corpsBrut)  en-tête x-bitnob-signature
//  Utilisé en mode simulation pour fabriquer le webhook "comme si"
//  Bitnob l'avait envoyé — le MÊME code de vérification est exercé.
// ════════════════════════════════════════════════════════════════
export function signerWebhook(corpsBrut) {
  const { webhookSecret } = configBitnob()
  const secret = webhookSecret || 'demo-webhook-secret'
  return crypto.createHmac('sha512', secret).update(corpsBrut).digest('hex')
}

// ════════════════════════════════════════════════════════════════
//  verifyWebhook(request)
//  Concept : "webhook" = Bitnob APPUIE sur notre serveur (HTTP POST)
//  le jour où une facture est payée. Sans vérification, n'importe qui
//  pourrait envoyer un faux POST : on vérifie donc la signature
//  HMAC-SHA512 (en-tête x-bitnob-signature) calculée sur le CORPS
//  BRUT de la requête.
//  Retourne { ok, evenement: { event, eventId, data } }.
// ════════════════════════════════════════════════════════════════
export function verifyWebhook(request) {
  const { webhookSecret } = configBitnob()
  const signature = request.headers['x-bitnob-signature']
  const corpsBrut = request.rawBody // chaîne EXACTE reçue (voir server/app.js)

  // Fail closed : pas de secret configuré ou pas de signature → refus.
  const secret = webhookSecret || (configBitnob().mock ? 'demo-webhook-secret' : '')
  if (!secret || !signature || typeof corpsBrut !== 'string') {
  return { ok: false, raison: 'signature ou secret webhook manquant' }
  }

  // La doc ne précise pas l'encodage (hex ou base64) : on calcule les
  // deux et on accepte celle qui correspond.
  const attenduHex = crypto.createHmac('sha512', secret).update(corpsBrut).digest('hex')
  const attenduB64 = crypto.createHmac('sha512', secret).update(corpsBrut).digest('base64')
  const sig = Buffer.from(String(signature))
  const egal = (a, b) => a.length === b.length && crypto.timingSafeEqual(a, b) // comparaison "constant time"

  if (!egal(sig, Buffer.from(attenduHex)) && !egal(sig, Buffer.from(attenduB64))) {
  return { ok: false, raison: 'signature invalide' }
  }

  let evenement
  try {
  evenement = JSON.parse(corpsBrut)
  } catch {
  return { ok: false, raison: 'corps JSON illisible' }
  }
  // Bitnob envoie { event, event_id, data } (ex. event =
  // "btc.lightning.received.success" pour un encaissement Lightning).
  return {
  ok: true,
  evenement: {
  event: evenement.event || evenement.type || '',
  eventId: evenement.event_id || evenement.id || '',
  data: evenement.data || evenement,
  },
  }
}
