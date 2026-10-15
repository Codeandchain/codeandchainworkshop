// ════════════════════════════════════════════════════════════════
//  REGISTRE — mémoire minimale du serveur
//  • factures : les factures émises, indexées par id
//  • journal  : l'activité visible dans l'interface (webhooks reçus,
//  payouts envoyés…), les N plus récentes seulement
//  Tout vit EN MÉMOIRE : redémarrer le serveur remet à zéro.
//  Pour produire, stocke ce registre dans une vraie base (KV, SQL…).
// ════════════════════════════════════════════════════════════════

export const factures = new Map() // id -> facture
export const eventsVus = new Set() // event_id des webhooks déjà traités
export const journal = [] // { at, type, titre, detail, ok }

const MAX_JOURNAL = 40

export function noter(type, titre, detail = '', ok = true) {
  journal.unshift({ at: Date.now(), type, titre, detail, ok })
  if (journal.length > MAX_JOURNAL) journal.length = MAX_JOURNAL
}

// ── Les échanges, pour le « fil » de l'interface Banc ──────────
//  Chaque requête/réponse entre navigateur, serveur et Bitnob, dans
//  l'ordre : { n, at, cle, de, vers, type, http, entetes, corps,
//  verifs, bolt11, simule, ko }. Les signatures y sont TRONQUÉES et
//  les secrets n'y entrent jamais : tout ceci part au navigateur.
export const echanges = []
let compteurEchanges = 0
const MAX_ECHANGES = 80

export function noterEchange(e) {
  if (String(process.env.DEMO_TOOLS ?? 'true').toLowerCase() === 'false') return // fil coupé
  echanges.push({ n: ++compteurEchanges, at: Date.now(), ...e })
  if (echanges.length > MAX_ECHANGES) echanges.splice(0, echanges.length - MAX_ECHANGES)
}

// Le dernier webhook reçu (corps brut + signature), pour la démo
// « rejouer le webhook » : on le renvoie tel quel au serveur.
export const dernierWebhook = { corps: null, signature: null }

// Expiration des factures non payées (appelé par la tickeuse du serveur)
export function expirerFactures() {
  const maintenant = Date.now()
  for (const f of factures.values()) {
  if (f.status === 'pending' && maintenant > f.expiresAt) f.status = 'expired'
  }
}
