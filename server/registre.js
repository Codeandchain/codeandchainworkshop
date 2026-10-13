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

// Expiration des factures non payées (appelé par la tickeuse du serveur)
export function expirerFactures() {
  const maintenant = Date.now()
  for (const f of factures.values()) {
  if (f.status === 'pending' && maintenant > f.expiresAt) f.status = 'expired'
  }
}
