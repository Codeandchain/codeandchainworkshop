// ════════════════════════════════════════════════════════════════
//  Appels à NOTRE serveur, avec le code admin si on en a un.
//  Le code est gardé dans sessionStorage : il disparaît quand on
//  ferme l'onglet. Ce n'est PAS une clé Bitnob — celles-ci ne
//  quittent jamais le serveur.
// ════════════════════════════════════════════════════════════════
import { ref, watch } from 'vue'

const CLE = 'bitnob-demo-admin'
const lire = () => { try { return sessionStorage.getItem(CLE) || '' } catch { return '' } }

export const codeAdmin = ref(lire())
watch(codeAdmin, (v) => { try { sessionStorage.setItem(CLE, v) } catch { /* navigation privée */ } })

export function api(chemin, options = {}) {
  const headers = { ...(options.headers || {}) }
  if (codeAdmin.value) headers['x-admin-token'] = codeAdmin.value
  return fetch(chemin, { ...options, headers })
}
