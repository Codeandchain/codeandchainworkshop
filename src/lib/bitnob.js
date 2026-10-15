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
import { encoderBolt11, decoderBolt11 } from './bolt11.js'

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

// ── Traçage des échanges (pour le « fil » de l'interface Banc) ─
//  Le serveur s'abonne avec onEchange() ; ce module lui décrit chaque
//  appel à Bitnob. Les valeurs longues sont abrégées, les signatures
//  tronquées, et la clé secrète n'apparaît JAMAIS.
let handlerEchange = null
export function onEchange(handler) {
  handlerEchange = handler
}
function tracer(echange) {
  try { handlerEchange?.(echange) } catch (e) { console.error('traçage :', e) }
}
export function abreger(valeur) {
  if (typeof valeur === 'string') return valeur.length > 48 ? `${valeur.slice(0, 24)}…${valeur.slice(-8)}` : valeur
  if (Array.isArray(valeur)) return valeur.map(abreger)
  if (valeur && typeof valeur === 'object') return Object.fromEntries(Object.entries(valeur).map(([k, v]) => [k, abreger(v)]))
  return valeur
}
const enTetesVisibles = (h) => [
  ['X-Auth-Client', `${h['X-Auth-Client'].slice(0, 6)}…`],
  ['X-Auth-Timestamp', h['X-Auth-Timestamp']],
  ['X-Auth-Nonce', h['X-Auth-Nonce']],
  ['X-Auth-Signature', abreger(h['X-Auth-Signature'])],
]

// Petit client HTTP vers l'API Bitnob (timeout 10 s).
//  `cle` (facultatif) : nom de l'échange dans le fil. Sans cle, pas
//  de traçage (ex. le polling du statut, qui tourne chaque seconde).
async function appelerBitnob(methode, chemin, corps = null, cle = null) {
  const { baseUrl } = configBitnob()
  const brut = corps ? JSON.stringify(corps) : ''
  const enTetes = enTetesAuth(brut)
  if (cle) {
  tracer({ cle: `bitnob.${cle}`, de: 'serveur', vers: 'bitnob', type: 'req',
  http: { methode, url: `${baseUrl}${chemin}` }, entetes: enTetesVisibles(enTetes), corps: corps && abreger(corps) })
  }
  let reponse
  try {
  reponse = await fetch(`${baseUrl}${chemin}`, {
  method: methode,
  headers: enTetes,
  body: brut || undefined,
  signal: AbortSignal.timeout(10_000),
  })
  } catch (e) {
  if (cle) tracer({ cle: `bitnob.${cle}.rep`, de: 'bitnob', vers: 'serveur', type: 'rep', ko: true, http: { methode, url: chemin, statut: 'réseau' }, corps: { erreur: String(e.message || e) } })
  throw e
  }
  const data = await reponse.json().catch(() => ({}))
  if (cle) {
  tracer({ cle: `bitnob.${cle}.rep`, de: 'bitnob', vers: 'serveur', type: 'rep', ko: !reponse.ok,
  http: { methode, url: chemin, statut: reponse.status }, corps: abreger(data), bolt11: (data.data ?? data)?.request })
  }
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
const sha256Hex = (hex) => crypto.createHash('sha256').update(Buffer.from(hex, 'hex')).digest('hex')

// Clé privée du "nœud de démo" qui signe les factures simulées.
// Fixe (dérivée d'une phrase) : la clé publique affichée par le
// décodeur reste la même d'une facture à l'autre, comme un vrai nœud.
const CLE_NOEUD_DEMO = crypto.createHash('sha256').update('noeud-demo-code-and-chain-dakar').digest()

// Les preimages restent SECRÈTES jusqu'au paiement (comme chez un
// vrai nœud) : elles ne sont jamais renvoyées avant le statut "paid".
const preimagesMock = new Map()

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
  //  Comme un vrai nœud : on tire d'abord la preimage (le secret),
  //  et le payment_hash est son sha256. Payer = obtenir la preimage.
  const preimage = hexAlea(32)
  const paymentHash = sha256Hex(preimage)
  const description = metadata.description || 'Démo Bitnob'
  const facture = {
  id,
  reference,
  paymentHash,
  montantSats: amountSats,
  description,
  // Une VRAIE facture BOLT11 (réseau regtest), signée par le nœud de démo
  paymentRequest: encoderBolt11({
  reseau: 'bcrt', montantSats: amountSats, paymentHash, paymentSecret: hexAlea(32),
  description, expiration: cfg.expiryS, clePrivee: CLE_NOEUD_DEMO,
  }),
  status: 'pending',
  preimage: '',
  expiresAt: Date.now() + cfg.expiryS * 1000,
  traitee: false,
  mock: true,
  }
  preimagesMock.set(id, preimage)
  facturesMock.set(id, facture)
  // Le fil montre l'appel que l'on AURAIT fait — marqué « simulé »
  tracer({ cle: 'bitnob.facture', de: 'serveur', vers: 'bitnob', type: 'req', simule: true,
  http: { methode: 'POST', url: `${cfg.baseUrl}/api/lightning/invoices` },
  entetes: [['X-Auth-Signature', '(simulation : aucune clé, aucun appel)']],
  corps: { reference, amount: amountSats, description, expiry: cfg.expiryS } })
  tracer({ cle: 'bitnob.facture.rep', de: 'bitnob', vers: 'serveur', type: 'rep', simule: true,
  http: { methode: 'POST', url: '/api/lightning/invoices', statut: 201 },
  corps: abreger({ id, reference, payment_hash: paymentHash, request: facture.paymentRequest, status: 'pending' }),
  bolt11: facture.paymentRequest })

  // ⏱ Auto-paiement factice après MOCK_PAYMENT_DELAY_MS (2 s) :
  //  le serveur recevra ensuite un webhook signé, comme en vrai.
  setTimeout(() => {
  const f = facturesMock.get(id)
  if (!f || f.status !== 'pending') return
  f.status = 'paid'
  f.preimage = preimagesMock.get(id) // révélée seulement maintenant
  tracer({ cle: 'reseau.paiement', de: 'bitnob', vers: 'bitnob', type: 'evt', simule: true,
  verifs: [[sha256Hex(f.preimage) === f.paymentHash, 'sha256(preimage) = payment_hash', `${abreger(f.preimage)} → ${abreger(f.paymentHash)}`]] })
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
  const reponse = await appelerBitnob('POST', '/api/lightning/invoices', corps, 'facture')
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
  //  Règle d'or : on DÉCODE avant de payer. Montant, expiration,
  //  destinataire… tout est dans la facture : on vérifie avant
  //  d'envoyer le moindre sat.
  let decodee
  try {
  decodee = decoderBolt11(bolt11)
  } catch (e) {
  tracer({ cle: 'payout.decode', de: 'serveur', vers: 'serveur', type: 'soi', ko: true, verifs: [[false, 'Facture BOLT11 lisible', e.message]] })
  throw new Error(`Facture illisible : ${e.message}`)
  }
  const verifs = [
  [true, 'Checksum bech32 valide'],
  [decodee.signatureValide, 'Signature du nœud valide', abreger(decodee.noeud || '')],
  [!decodee.expiree, decodee.expiree ? 'Facture expirée' : 'Facture pas expirée', new Date(decodee.expireLe).toLocaleString('fr-FR')],
  [true, `Montant : ${decodee.montantSats ?? 'libre'} sats`, decodee.description || ''],
  ]
  tracer({ cle: 'payout.decode', de: 'serveur', vers: 'serveur', type: 'soi', ko: verifs.some((v) => !v[0]), verifs, bolt11: bolt11.trim() })
  if (decodee.expiree) throw new Error('Facture expirée : le destinataire n\'attend plus ce paiement')
  if (!decodee.signatureValide) throw new Error('Signature de la facture invalide')

  if (cfg.mock) {
  tracer({ cle: 'bitnob.pay', de: 'serveur', vers: 'bitnob', type: 'req', simule: true,
  http: { methode: 'POST', url: `${cfg.baseUrl}/api/lightning/pay` },
  entetes: [['X-Auth-Signature', '(simulation : aucune clé, aucun appel)']],
  corps: abreger({ invoice: bolt11.trim(), reference: reference || 'demo-payout' }) })
  await new Promise((r) => setTimeout(r, 1200)) // on laisse le temps de voir
  tracer({ cle: 'bitnob.pay.rep', de: 'bitnob', vers: 'serveur', type: 'rep', simule: true,
  http: { methode: 'POST', url: '/api/lightning/pay', statut: 200 }, corps: { status: 'success', amount_sat: decodee.montantSats } })
  return {
  id: `pay-${crypto.randomUUID().slice(0, 8)}`,
  status: 'success',
  feeSats: Math.floor(Math.random() * 3),
  montantSats: decodee.montantSats,
  paymentHash: decodee.paymentHash,
  reference: reference || 'demo-payout',
  mock: true,
  }
  }

  const ref = reference || `payout-${crypto.randomUUID().slice(0, 8)}`
  const reponse = await appelerBitnob('POST', '/api/lightning/pay', {
  invoice: bolt11.trim(),
  reference: ref,
  }, 'pay')
  const f = reponse.data ?? reponse
  return {
  id: f.id,
  status: f.status || 'success',
  feeSats: Number(f.fee_sat ?? f.fees ?? 0),
  montantSats: f.amount_sat ? Number(f.amount_sat) : decodee.montantSats,
  paymentHash: decodee.paymentHash,
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
