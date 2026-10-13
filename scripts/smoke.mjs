// ════════════════════════════════════════════════════════════════
//  SMOKE TEST — vérifie le cycle complet en mode simulation.
//  Lance d'abord le serveur (npm start ou npm run dev), puis :
//  npm run smoke
// ════════════════════════════════════════════════════════════════
import crypto from 'node:crypto'
import dotenv from 'dotenv'

//  On lit le même .env que le serveur : le secret de webhook doit être
// identique des deux côtés pour que la signature de test soit valide.
dotenv.config()

const BASE = process.env.SMOKE_URL || 'http://localhost:3000'
const SECRET = process.env.BITNOB_WEBHOOK_SECRET || 'demo-webhook-secret'

let echecs = 0
const ok = (libelle, condition, detail = '') => {
  console.log(`${condition ? '[OK]  ' : '[KO]  '} ${libelle}${detail ? ` — ${detail}` : ''}`)
  if (!condition) echecs++
}
const pause = (ms) => new Promise((r) => setTimeout(r, ms))
const api = async (chemin, { method = 'GET', corps = null } = {}) => {
  const r = await fetch(`${BASE}${chemin}`, {
  method,
  headers: { 'Content-Type': 'application/json' },
  body: corps ? JSON.stringify(corps) : undefined,
  })
  return { status: r.status, data: await r.json().catch(() => ({})) }
}
const signer = (corps) => crypto.createHmac('sha512', SECRET).update(corps).digest('hex')

// ── 1. Mode simulation actif ───────────────────────────────────
let r = await api('/api/info')
ok('GET /api/info', r.status === 200)
ok('mode simulation actif', r.data.mock === true)

// ── 2. Création d'une facture ──────────────────────────────────
r = await api('/api/invoices', { method: 'POST', corps: { amount: 42, description: 'Smoke test' } })
ok('POST /api/invoices crée une facture', r.status === 201 && !!r.data.id && !!r.data.paymentRequest)
const facture = r.data

// ── 3. Elle est payée automatiquement (~2 s) via webhook signé ──
let statut = 'pending'
for (let i = 0; i < 8 && statut === 'pending'; i++) {
  await pause(700)
  const s = await api(`/api/invoices/${facture.id}`)
  statut = s.data.status
}
ok('facture payée automatiquement', statut === 'paid', `statut=${statut}`)

// ── 4. Le webhook est apparu dans le journal ───────────────────
r = await api('/api/journal')
const webhook = (r.data.journal || []).find((e) => e.type === 'webhook' && e.ok && /créditée/.test(e.detail))
ok('webhook reçu et traité (journal)', !!webhook, webhook?.detail)

// ── 5. Idempotence : rejouer le même webhook ne change rien ────
const corps = JSON.stringify({
  event: 'btc.lightning.received.success',
  event_id: `mock-${facture.id}`,
  data: { id: facture.id, reference: facture.reference, status: 'paid' },
})
const rejoue = await fetch(`${BASE}/api/webhook/bitnob`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'x-bitnob-signature': signer(corps) },
  body: corps,
}).then((x) => x.json())
ok('webhook rejoué ignoré (idempotence)', rejoue.dejaVu === true || rejoue.dejaTraitee === true, JSON.stringify(rejoue))

// ── 6. Mauvaise signature  401 ────────────────────────────────
const refus = await fetch(`${BASE}/api/webhook/bitnob`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'x-bitnob-signature': 'mauvaise' },
  body: corps,
})
ok('mauvaise signature refusée (401)', refus.status === 401)

// ── 7. Payout simulé ───────────────────────────────────────────
r = await api('/api/pay', { method: 'POST', corps: { invoice: facture.paymentRequest } })
ok('POST /api/pay (payout simulé)', r.status === 200 && r.data.status === 'success')

// ── Bilan ──────────────────────────────────────────────────────
console.log('')
if (echecs === 0) {
  console.log('TOUT FONCTIONNE — le cycle complet invoice → webhook → payout est validé !')
  process.exit(0)
} else {
  console.log(`${echecs} test(s) en échec.`)
  process.exit(1)
}
