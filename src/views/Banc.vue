<script setup>
// ════════════════════════════════════════════════════════════════
//  INTERFACE « BANC » — on VOIT ce qui circule :
//   • Commande (gauche)  : recevoir / envoyer, comme l'interface
//                          classique, + les tests de sécurité
//   • Le fil (centre)    : diagramme de séquence des VRAIS échanges
//                          navigateur ↔ serveur ↔ Bitnob, lus sur
//                          GET /api/echanges (le serveur les trace)
//   • Inspecteur (droite): la requête choisie — en-têtes, corps JSON,
//                          vérifications, anatomie de la facture
//  Le navigateur ne fabrique AUCUN échange : il affiche ce que le
//  serveur a réellement fait, en simulation comme avec de vraies clés.
// ════════════════════════════════════════════════════════════════
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import QRCode from 'qrcode'
import AnatomieBolt11 from '../components/AnatomieBolt11.vue'
import { decoderBolt11 } from '../lib/bolt11.js'
import { api, codeAdmin } from '../lib/api.js'

const info = ref(null)

// ── Les échanges tracés par le serveur ─────────────────────────
const echanges = ref([])
const debut = ref(0)        // le fil n'affiche que les échanges après ce n°
const selection = ref(null) // n° de l'échange inspecté
const epingle = ref(false)  // true = l'utilisateur a cliqué : on ne suit plus le dernier
const dernierN = computed(() => echanges.value.at(-1)?.n ?? 0)
const fil = computed(() => echanges.value.filter((e) => e.n > debut.value))
const choisi = computed(() => fil.value.find((e) => e.n === selection.value) ?? null)

// 'ok' | 'verrouille' (code admin requis) | 'coupe' (DEMO_TOOLS=false)
const etatFil = ref('ok')
async function lireEchanges() {
  try {
    const r = await api(`/api/echanges?depuis=${dernierN.value}`)
    etatFil.value = r.status === 401 ? 'verrouille' : r.status === 404 ? 'coupe' : 'ok'
    if (!r.ok) return
    const d = await r.json()
    if (!d.echanges?.length) return
    echanges.value = [...echanges.value, ...d.echanges].slice(-200)
    if (!epingle.value) selection.value = dernierN.value
  } catch { /* le prochain tick réessaiera */ }
}
function nouveauFil() { debut.value = dernierN.value; epingle.value = false; selection.value = null }
function inspecter(n) { selection.value = n; epingle.value = true }

// ── Commande : recevoir ────────────────────────────────────────
const onglet = ref('recevoir')
const montant = ref(2500)
const description = ref('Café Touba x2')
const facture = ref(null)
const qr = ref('')
const erreur = ref(null)
const occupe = ref(false)
const SATS_EN_FCFA = 0.62 // taux indicatif, à ajuster le jour J
const fmt = (n) => Number(n).toLocaleString('fr-FR')
const conversion = computed(() => {
  const s = Math.max(0, Number(montant.value) || 0)
  return `≈ ${fmt(Math.round(s * SATS_EN_FCFA))} FCFA · ${(s / 1e8).toFixed(8).replace(/\.?0+$/, '')} BTC (taux indicatif)`
})

async function creerFacture() {
  erreur.value = null
  occupe.value = true
  nouveauFil()
  try {
    const r = await api('/api/invoices', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount: Number(montant.value), description: description.value }),
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.erreur || `HTTP ${r.status}`)
    facture.value = d
    qr.value = await QRCode.toDataURL(d.paymentRequest.toUpperCase(), { margin: 1, width: 240, color: { dark: '#13201a', light: '#ffffff' } })
    surveiller(d.id)
  } catch (e) { erreur.value = String(e.message || e) }
  finally { occupe.value = false }
}

let minuteurFacture = null
function surveiller(id) {
  clearInterval(minuteurFacture)
  minuteurFacture = setInterval(async () => {
    try {
      const d = await api(`/api/invoices/${id}`).then((r) => r.json())
      facture.value = { ...facture.value, ...d }
      if (d.status !== 'pending') clearInterval(minuteurFacture)
    } catch { /* idem */ }
  }, 1000)
}

// ── Commande : envoyer ─────────────────────────────────────────
const aPayer = ref('')
const resultat = ref(null)
const decodee = computed(() => {
  if (!aPayer.value.trim()) return null
  try { return { d: decoderBolt11(aPayer.value) } } catch (e) { return { erreur: e.message } }
})
const payable = computed(() => decodee.value?.d && !decodee.value.d.expiree && decodee.value.d.signatureValide)

async function payer() {
  erreur.value = null
  resultat.value = null
  occupe.value = true
  nouveauFil()
  try {
    const r = await api('/api/pay', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ invoice: aPayer.value }),
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.erreur || `HTTP ${r.status}`)
    resultat.value = d
  } catch (e) { erreur.value = String(e.message || e) }
  finally { occupe.value = false }
}
// Pratique en atelier : la dernière facture créée est proposée au payout
watch(facture, (f) => { if (f?.paymentRequest && !aPayer.value) aPayer.value = f.paymentRequest })

// ── Tests de sécurité (de vraies requêtes vers ton serveur) ────
const webhookRecu = computed(() => echanges.value.some((e) => e.cle === 'webhook' && !e.ko))
async function rejouer() {
  erreur.value = null
  const r = await api('/api/demo/rejouer-webhook', { method: 'POST' })
  if (r.status === 401) erreur.value = 'Code admin requis pour rejouer le webhook.'
}
async function forger() {
  // Le navigateur joue l'attaquant : il poste un « paiement » sans
  // connaître le secret du webhook, donc sans la bonne signature.
  // Volontairement fetch() et pas api() : un attaquant n'a pas de code admin.
  await fetch('/api/webhook/bitnob', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'x-bitnob-signature': 'deadbeef' },
    body: JSON.stringify({
      event: 'btc.lightning.received.success',
      event_id: `faux-${Math.random().toString(16).slice(2, 10)}`,
      data: { id: facture.value?.id ?? 'inconnue', status: 'paid' },
    }),
  })
}

// ── Mode projecteur : tout grossit (les tailles sont en rem) ───
const grand = ref(false)
watch(grand, (g) => { document.documentElement.style.fontSize = g ? '18px' : '' })

let minuteurs = []
onMounted(async () => {
  info.value = await api('/api/info').then((r) => r.json()).catch(() => null)
  lireEchanges()
  minuteurs.push(setInterval(lireEchanges, 800))
})
onUnmounted(() => {
  minuteurs.forEach(clearInterval)
  clearInterval(minuteurFacture)
  document.documentElement.style.fontSize = ''
})

// ════════════════════════════════════════════════════════════════
//  Présentation des échanges : couloirs, légendes, explications
// ════════════════════════════════════════════════════════════════
const COULOIRS = { nav: 0, serveur: 1, bitnob: 2 }
const NOMS = ['Navigateur', 'Ton serveur', 'Bitnob']
const COULEURS = ['var(--nav)', 'var(--vert)', 'var(--jaune)']
const X = [100 / 6, 50, 500 / 6] // abscisse des 3 lignes de vie, en %

// Les légendes passent par v-html : tout ce qui vient du serveur
// (et donc, potentiellement, d'un webhook forgé) est échappé.
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)
const statut = (e) => esc(e.http?.statut)
const TEXTES = {
  'facture.demande': { titre: 'Le navigateur demande une facture', legende: () => '<b>POST</b> /api/invoices',
    texte: 'Le navigateur ne parle jamais à Bitnob. Il demande à TON serveur, qui seul détient la clé secrète.' },
  'bitnob.facture': { titre: 'Le serveur appelle Bitnob, signé HMAC', legende: () => '<b>POST</b> /lightning/invoices',
    texte: 'Pas de clé dans un header : le serveur signe CLIENT_ID:TIMESTAMP:NONCE:PAYLOAD avec sa clé secrète. Bitnob refait le calcul et compare. Le nonce empêche de rejouer la requête.' },
  'bitnob.facture.rep': { titre: 'Bitnob renvoie la facture', legende: (e) => `<b>${statut(e)}</b> facture BOLT11`,
    texte: 'La facture BOLT11 est un message signé par le nœud. Tout se lit dedans : survole chaque morceau ci-dessous.' },
  'facture.reponse': { titre: 'Le navigateur affiche le QR', legende: (e) => `<b>${statut(e)}</b> { id, paymentRequest }`,
    texte: 'Le QR contient exactement la chaîne BOLT11. N\'importe quel wallet Lightning peut la payer.' },
  'reseau.paiement': { titre: 'Un wallet paie la facture', legende: () => '⚡ payée sur le réseau',
    texte: 'Le paiement traverse le réseau Lightning jusqu\'au nœud. En échange des sats, le payeur reçoit la preimage : sha256(preimage) = payment_hash.' },
  webhook: { titre: 'Bitnob prévient ton serveur', legende: (e) => `<b>POST</b> webhook → ${statut(e)}`,
    texte: 'C\'est le webhook qui fait foi, jamais le navigateur. Le serveur répond vite, sinon Bitnob réessaie jusqu\'à 3 fois.' },
  'webhook.rejeu': { titre: 'Le même webhook revient', legende: (e) => `<b>POST</b> rejeu → ${statut(e)}`,
    texte: 'Octet pour octet le dernier webhook reçu, signature comprise : comme un retry de Bitnob. La signature est valide, mais l\'event_id est déjà connu, donc rien n\'est crédité.' },
  'webhook.refuse': { titre: 'Webhook refusé', legende: (e) => `<b>POST</b> faux webhook → <span class="ko-tag">${statut(e)}</span>`,
    texte: 'Sans le secret du webhook, impossible de produire la bonne signature. Le serveur refuse tout ce qui n\'est pas signé (fail closed).' },
  'webhook.verif': { titre: 'Les vérifications du serveur', legende: (e) => (e.verifs || []).map((v) => `<span class="${v[0] ? 'ok-tag' : 'ko-tag'}">${v[0] ? '✓' : '✗'}</span>`).join(' ') + ` ${esc(e.verifs?.at(-1)?.[1]?.replace(/^event_id \S+/, 'event_id'))}`,
    texte: 'Avant de créditer quoi que ce soit, le serveur vérifie tout lui-même, dans cet ordre.' },
  'polling.confirme': { titre: 'Paiement confirmé par le polling', legende: () => '✓ confirmé sans webhook',
    texte: 'Le webhook s\'est perdu ? Le serveur a revérifié le statut chez Bitnob. C\'est le filet de sécurité.' },
  'statut.demande': { titre: 'Le navigateur demande le statut', legende: () => '<b>GET</b> /api/invoices/:id',
    texte: 'Le navigateur interroge chaque seconde (seul le moment où il découvre « payée » est montré ici). Il affiche, il ne décide pas.' },
  'statut.reponse': { titre: 'Statut : payée', legende: (e) => `<b>${statut(e)}</b> paid + preimage`,
    texte: 'La preimage est la preuve de paiement. Le navigateur peut la vérifier lui-même.' },
  'payout.demande': { titre: 'Le navigateur demande un payout', legende: () => '<b>POST</b> /api/pay',
    texte: 'Envoyer des sats, c\'est le chemin inverse : on paie la facture de quelqu\'un d\'autre.' },
  'payout.decode': { titre: 'On lit la facture avant de payer', legende: (e) => (e.ko ? '<span class="ko-tag">✗</span> facture refusée' : '<span class="ok-tag">✓</span> BOLT11 décodée et vérifiée'),
    texte: 'Montant, destinataire, expiration : tout est dans la facture. Le serveur vérifie avant d\'envoyer le moindre sat.' },
  'bitnob.pay': { titre: 'Le serveur paie via Bitnob', legende: () => '<b>POST</b> /lightning/pay',
    texte: 'Même signature HMAC que pour créer une facture.' },
  'bitnob.pay.rep': { titre: 'Bitnob confirme le paiement', legende: (e) => `<b>${statut(e)}</b> ${esc(e.corps?.status)}`,
    texte: 'Le paiement est parti sur le réseau Lightning.' },
  'payout.reponse': { titre: 'Résultat du payout', legende: (e) => `<b>${statut(e)}</b> ${e.ko ? 'échec' : 'payé'}`,
    texte: 'Fin du cycle sortant.' },
}
const texte = (e) => TEXTES[e.cle] ?? { titre: e.cle, legende: () => e.cle, texte: '' }

function geometrie(e) {
  const de = COULOIRS[e.de], vers = COULOIRS[e.vers]
  const gauche = Math.min(X[de], X[vers]), largeur = Math.abs(X[de] - X[vers])
  return {
    de, vers, gauche, largeur,
    sens: X[vers] > X[de] ? 'droite' : 'gauche',
    couleur: e.ko ? 'var(--rouge)' : COULEURS[de],
  }
}
const expediteur = (e) => (e.cle === 'webhook.refuse' ? 'Inconnu (attaquant ?)' : NOMS[COULOIRS[e.de]])

// JSON coloré (le texte est échappé AVANT d'ajouter nos balises)
function json(o) {
  return JSON.stringify(o, null, 2)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"([^"\\]+)":/g, '<span class="k">"$1"</span>:')
    .replace(/: "((?:[^"\\]|\\.)*)"/g, ': <span class="s">"$1"</span>')
    .replace(/: (-?\d+(?:\.\d+)?|true|false|null)/g, ': <span class="n">$1</span>')
}
const heure = (at) => new Date(at).toLocaleTimeString('fr-FR')
</script>

<template>
  <div class="banc">
    <!-- ═══ Barre du haut ═══ -->
    <header class="barre">
      <div class="marque"><h1>Banc <b>⚡</b> Lightning</h1><span>Code &amp; Chain Dakar · Bitnob</span></div>
      <span v-if="info" class="puce" :class="{ ok: !info.mock }"><i></i>{{ info.mock ? 'Simulation, aucun vrai sat' : `Bitnob réel · ${info.baseUrl}` }}</span>
      <span v-if="info" class="puce" :class="{ ok: info.webhooksConfigures }"><i></i><span class="mono">{{ info.webhookUrl }}</span></span>
      <label v-if="info?.protege" class="code-admin" for="b-code-admin">
        <span>Code admin</span>
        <input id="b-code-admin" v-model.trim="codeAdmin" type="password" autocomplete="off" placeholder="ADMIN_TOKEN" />
        <span class="etat-code" :class="etatFil === 'verrouille' ? 'ko-tag' : 'ok-tag'">{{ etatFil === 'verrouille' ? '✗' : '✓' }}</span>
      </label>
      <button class="bouton-texte" :aria-pressed="grand" @click="grand = !grand">Mode projecteur</button>
    </header>

    <main class="atelier">
      <!-- ═══ Commande ═══ -->
      <section class="panneau" aria-labelledby="t-commande">
        <header><h2 id="t-commande">Commande</h2><span class="etiquette">Ton app</span></header>
        <div class="corps">
          <div class="onglets" role="tablist">
            <button id="tab-recevoir" role="tab" :aria-selected="onglet === 'recevoir'" @click="onglet = 'recevoir'">Recevoir</button>
            <button id="tab-envoyer" role="tab" :aria-selected="onglet === 'envoyer'" @click="onglet = 'envoyer'">Envoyer</button>
          </div>

          <div v-if="onglet === 'recevoir'" class="pile" role="tabpanel" aria-labelledby="tab-recevoir">
            <label for="b-montant">Montant
              <span class="montant"><input id="b-montant" v-model.number="montant" class="champ" type="number" min="1" max="20000000" /><span>sats</span></span>
              <span class="conversion">{{ conversion }}</span>
            </label>
            <label for="b-description">Description
              <input id="b-description" v-model="description" class="champ" maxlength="120" />
            </label>
            <button class="action" :disabled="occupe" @click="creerFacture"><span class="eclair">⚡</span> {{ occupe ? 'Création…' : 'Créer la facture' }}</button>

            <div v-if="facture" class="facture">
              <div class="qr"><img v-if="qr" :src="qr" alt="QR code de la facture Lightning" /></div>
              <dl>
                <dt>Statut</dt>
                <dd>
                  <span class="statut" :class="{ pending: 'attente', paid: 'paye' }[facture.status] || 'refus'">
                    ● {{ { pending: 'En attente', paid: 'Payée', expired: 'Expirée', failed: 'Échouée' }[facture.status] || facture.status }}
                  </span>
                </dd>
                <dt>Montant</dt><dd>{{ fmt(facture.montantSats) }} sats</dd>
                <dt>Référence</dt><dd class="mono">{{ facture.reference }}</dd>
              </dl>
              <div class="bolt">{{ facture.paymentRequest }}</div>
            </div>
          </div>

          <div v-else class="pile" role="tabpanel" aria-labelledby="tab-envoyer">
            <label for="b-a-payer">Facture BOLT11 à payer
              <textarea id="b-a-payer" v-model="aPayer" class="champ mono" rows="4" placeholder="lnbc…"></textarea>
            </label>
            <p v-if="decodee?.erreur" class="erreur">{{ decodee.erreur }}</p>
            <dl v-else-if="decodee?.d" class="resume">
              <dt>Montant</dt><dd>{{ decodee.d.montantSats === null ? 'libre' : `${fmt(decodee.d.montantSats)} sats` }}</dd>
              <dt>Pour</dt><dd>{{ decodee.d.description ? `« ${decodee.d.description} »` : '—' }}</dd>
              <dt>Expire</dt><dd :class="{ 'ko-tag': decodee.d.expiree }">{{ new Date(decodee.d.expireLe).toLocaleString('fr-FR') }}{{ decodee.d.expiree ? ' (expirée)' : '' }}</dd>
              <dt>Nœud</dt><dd class="mono">{{ decodee.d.noeud?.slice(0, 10) }}…{{ decodee.d.noeud?.slice(-6) }}</dd>
            </dl>
            <p v-if="info && !info.payoutActif" class="erreur">Payout réel désactivé : définis ADMIN_TOKEN dans le .env du serveur.</p>
            <button class="action" :disabled="occupe || !payable || (info && !info.payoutActif)" @click="payer">
              <span class="eclair">⚡</span> {{ occupe ? 'Envoi sur le réseau…' : payable && decodee.d.montantSats !== null ? `Payer ${fmt(decodee.d.montantSats)} sats` : 'Payer' }}
            </button>
            <p v-if="resultat" class="succes">Payé · frais {{ resultat.feeSats }} sat</p>
          </div>

          <p v-if="erreur" class="erreur">{{ erreur }}</p>

          <div class="attaques">
            <span class="etiquette">Tests de sécurité</span>
            <p>De vraies requêtes vers ton serveur. Regarde le fil.</p>
            <button v-if="info?.outilsDemo !== false" class="attaque" :disabled="!webhookRecu" @click="rejouer">
              <strong>Rejouer le webhook</strong><small>{{ webhookRecu ? 'Le dernier reçu, signature comprise' : 'Attends qu\'une facture soit payée' }}</small>
            </button>
            <button class="attaque" @click="forger">
              <strong>Forger un faux webhook</strong><small>« Facture payée », sans la bonne signature</small>
            </button>
          </div>
        </div>
      </section>

      <!-- ═══ Le fil ═══ -->
      <section class="panneau fil" aria-labelledby="t-fil">
        <header>
          <h2 id="t-fil">Le fil des échanges</h2>
          <span class="etiquette">{{ fil.length }} échange{{ fil.length > 1 ? 's' : '' }}</span>
        </header>
        <div class="scene-defile">
          <div class="couloirs">
            <div class="couloir" style="--c: var(--nav)"><strong>Navigateur</strong><small>Vue, ce que voit l'utilisateur</small></div>
            <div class="couloir" style="--c: var(--vert)"><strong>Ton serveur</strong><small>Express, détient les secrets</small></div>
            <div class="couloir" style="--c: var(--jaune)"><strong>Bitnob · Lightning</strong><small>API + réseau</small></div>
          </div>
          <div class="scene" role="list">
            <button
              v-for="(e, i) in fil" :key="e.n"
              class="etape" :class="{ ko: e.ko }" role="listitem"
              :aria-current="e.n === selection" :aria-label="`Étape ${i + 1} : ${texte(e).titre}`"
              @click="inspecter(e.n)"
            >
              <span class="num">{{ String(i + 1).padStart(2, '0') }}</span>
              <template v-if="e.type === 'soi'">
                <span class="boucle" :style="{ left: `${X[geometrie(e).de]}%`, '--c': geometrie(e).couleur }" v-html="texte(e).legende(e)"></span>
              </template>
              <template v-else-if="e.type === 'evt'">
                <span class="evenement" :style="{ left: `${X[geometrie(e).de]}%` }">{{ texte(e).legende(e) }}{{ e.simule ? ' · simulé' : '' }}</span>
              </template>
              <template v-else>
                <span class="fleche" :class="[geometrie(e).sens, { reponse: e.type === 'rep' }]"
                  :style="{ left: `${geometrie(e).gauche}%`, width: `${geometrie(e).largeur}%`, '--c': geometrie(e).couleur }">
                  <span class="trait-fleche"></span>
                </span>
                <span class="legende-fleche" :style="{ left: `${geometrie(e).gauche}%`, right: `${100 - geometrie(e).gauche - geometrie(e).largeur}%` }"
                  v-html="texte(e).legende(e)"></span>
              </template>
              <span v-if="e.simule && e.type !== 'evt'" class="simule">simulé</span>
            </button>
            <div v-if="facture?.status === 'pending' && onglet === 'recevoir'" class="attente-ligne"><i></i> En attente du paiement…</div>
            <p v-if="etatFil === 'verrouille'" class="vide">Fil protégé : entre le code admin en haut de la page.</p>
            <p v-else-if="etatFil === 'coupe'" class="vide">Fil désactivé sur ce serveur (DEMO_TOOLS=false).</p>
            <p v-else-if="!fil.length" class="vide">Crée une facture : chaque échange apparaîtra ici, dans l'ordre.</p>
          </div>
        </div>
        <footer>
          <span><i class="mini"></i> requête</span>
          <span><i class="mini tirets"></i> réponse</span>
          <span>Clique une ligne pour l'inspecter</span>
        </footer>
      </section>

      <!-- ═══ Inspecteur ═══ -->
      <section class="panneau inspecteur" aria-labelledby="t-insp" aria-live="polite">
        <header>
          <h2 id="t-insp">Inspecteur</h2>
          <span v-if="choisi" class="etiquette">{{ heure(choisi.at) }}</span>
        </header>
        <div v-if="choisi" class="corps">
          <div class="sens">
            <span class="c" :style="{ '--c': COULEURS[COULOIRS[choisi.de]] }">{{ expediteur(choisi) }}</span>
            <template v-if="choisi.de !== choisi.vers">
              → <span class="c" :style="{ '--c': COULEURS[COULOIRS[choisi.vers]] }">{{ NOMS[COULOIRS[choisi.vers]] }}</span>
            </template>
          </div>
          <h3>{{ texte(choisi).titre }}</h3>
          <p class="explication">{{ texte(choisi).texte }}</p>
          <p v-if="choisi.simule" class="note-simule">Simulation : cet appel n'a pas été envoyé à Bitnob. Le serveur montre ce qu'il aurait envoyé.</p>

          <div v-if="choisi.http" class="requete">
            <div class="ligne-http">
              <b>{{ choisi.http.methode }}</b><span>{{ choisi.http.url }}</span>
              <span v-if="choisi.http.statut" class="code-http" :class="{ 'ko-tag': choisi.ko }">→ {{ choisi.http.statut }}</span>
            </div>
            <dl v-if="choisi.entetes" class="entetes">
              <template v-for="[k, v] in choisi.entetes" :key="k">
                <dt>{{ k }}</dt><dd :class="{ secret: /signature/i.test(k) }">{{ v }}</dd>
              </template>
            </dl>
            <pre v-if="choisi.corps" class="json" v-html="json(choisi.corps)"></pre>
          </div>

          <ul v-if="choisi.verifs" class="verifs">
            <li v-for="([ok, t, d], i) in choisi.verifs" :key="i">
              <span :class="ok ? 'o' : 'x'">{{ ok ? '✓' : '✗' }}</span>
              <span>{{ t }}<small v-if="d">{{ d }}</small></span>
            </li>
          </ul>

          <div v-if="choisi.bolt11" class="anatomie-bloc">
            <span class="etiquette">Anatomie de la facture</span>
            <AnatomieBolt11 :facture="choisi.bolt11" />
          </div>

          <p v-if="choisi.entetes?.some(([k]) => /signature/i.test(k))" class="astuce">
            Surligné : la signature. Elle prouve que l'expéditeur connaît le secret, sans jamais l'envoyer.
          </p>
        </div>
        <p v-else class="vide">Clique un échange du fil pour voir la requête, ses en-têtes et ce que le serveur a vérifié.</p>
      </section>
    </main>
  </div>
</template>

<style scoped>
/* Banc : 3 colonnes, commande · fil · inspecteur. Couleurs du drapeau
   avec un rôle chacune : vert = serveur et validations, jaune = événements
   Lightning, rouge = refus. Clair et sombre suivent le système. */
.banc {
  --fond: #eef1ec; --surface: #ffffff; --surface-2: #f6f8f4;
  --encre: #13201a; --encre-2: #56645c; --trait: #d8dfd8;
  --vert: #00853f; --vert-doux: rgba(0, 133, 63, 0.12);
  --jaune: #f2c200; --jaune-doux: rgba(242, 194, 0, 0.2);
  --rouge: #d7191f; --rouge-doux: rgba(215, 25, 31, 0.1);
  --nav: #5a6b8c;
  --police: 'Archivo', 'Helvetica Neue', Arial, sans-serif;
  --mono: 'IBM Plex Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace;
  --r: 10px;
  /* pour le composant AnatomieBolt11 (partagé avec l'interface classique) */
  --bordure: var(--trait); --encre-doux: var(--encre-2);
  --fond-code: var(--surface-2); --fond-code-inline: var(--vert-doux); --couleur-code: var(--vert);

  min-height: 100vh; background: var(--fond); color: var(--encre);
  font-family: var(--police); line-height: 1.5;
  padding-inline: 16px; padding-block: 0 3rem;
}
@media (prefers-color-scheme: dark) {
  .banc {
    color-scheme: dark;
    --fond: #0d1411; --surface: #151f1a; --surface-2: #1b2721; --encre: #e4ece6; --encre-2: #9aaaa0;
    --trait: #2a3830; --vert: #2fbf6c; --vert-doux: rgba(47, 191, 108, 0.14);
    --jaune: #ffd23f; --jaune-doux: rgba(255, 210, 63, 0.16); --rouge: #ff5a5f; --rouge-doux: rgba(255, 90, 95, 0.13);
    --nav: #93a6cf;
  }
}
.banc * { box-sizing: border-box; }
:where(.banc) button { font: inherit; color: var(--encre); }
:where(.banc) :is(input, textarea) { font: inherit; color: inherit; }
.banc h1, .banc h2, .banc h3 { margin: 0; font-family: var(--police); letter-spacing: normal; text-wrap: balance; }
.mono { font-family: var(--mono); font-size: 0.86em; }
.etiquette { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--encre-2); }
.ok-tag, .banc :deep(.ok-tag) { color: var(--vert); font-weight: 700; }
.ko-tag, .banc :deep(.ko-tag) { color: var(--rouge); font-weight: 700; }

/* ── Barre du haut ── */
.barre { max-width: 1400px; margin: 0 auto; display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; padding-block: 1rem; border-bottom: 1px solid var(--trait); }
.marque { display: flex; align-items: baseline; gap: 0.6rem; margin-right: auto; flex-wrap: wrap; }
.marque h1 { font-stretch: 125%; font-weight: 800; font-size: 1.45rem; letter-spacing: -0.01em; }
.marque h1 b { color: var(--jaune); }
.marque span { color: var(--encre-2); font-size: 0.85rem; }
.puce { display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid var(--trait); background: var(--surface); border-radius: 99px; padding: 0.2rem 0.7rem; font-size: 0.8rem; max-width: 100%; overflow-wrap: anywhere; }
.puce i { flex: none; width: 8px; height: 8px; border-radius: 50%; background: var(--jaune); box-shadow: 0 0 0 3px var(--jaune-doux); }
.puce.ok i { background: var(--vert); box-shadow: 0 0 0 3px var(--vert-doux); }
.code-admin { display: inline-flex; flex-direction: row; align-items: center; gap: 0.4rem; font-size: 0.8rem; font-weight: 600; }
.code-admin input { width: 9rem; border: 1px solid var(--trait); background: var(--surface); border-radius: 8px; padding: 0.3rem 0.55rem; font-size: 0.82rem; }
.code-admin input:focus { outline: 2px solid var(--vert); outline-offset: 1px; }
.bouton-texte { border: 1px solid var(--trait); background: var(--surface); border-radius: 8px; padding: 0.3rem 0.7rem; cursor: pointer; font-size: 0.82rem; }
.bouton-texte[aria-pressed="true"] { background: var(--encre); color: var(--fond); border-color: var(--encre); }

/* ── L'atelier : 3 colonnes ── */
.atelier { max-width: 1400px; margin: 1rem auto 0; display: grid; grid-template-columns: 330px minmax(0, 1fr) 390px; gap: 1rem; align-items: start; }
@media (max-width: 1180px) { .atelier { grid-template-columns: 320px minmax(0, 1fr); } .inspecteur { grid-column: 1 / -1; } }
@media (max-width: 760px) { .atelier { grid-template-columns: 1fr; } }
.panneau { background: var(--surface); border: 1px solid var(--trait); border-radius: var(--r); min-width: 0; }
.panneau > header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--trait); }
.panneau > header h2 { font-size: 0.95rem; font-stretch: 110%; font-weight: 700; }
.corps { padding: 1rem; display: flex; flex-direction: column; gap: 0.85rem; }
.pile { display: flex; flex-direction: column; gap: 0.85rem; }

/* ── Commande ── */
.onglets { display: grid; grid-template-columns: 1fr 1fr; background: var(--surface-2); border-radius: 8px; padding: 3px; gap: 3px; }
.onglets button { border: 0; background: transparent; border-radius: 6px; padding: 0.45rem; cursor: pointer; font-weight: 600; color: var(--encre-2); }
.onglets button[aria-selected="true"] { background: var(--surface); color: var(--encre); box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08); }
label { display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.82rem; font-weight: 600; }
.champ { border: 1px solid var(--trait); background: var(--surface-2); border-radius: 8px; padding: 0.55rem 0.7rem; width: 100%; }
.champ:focus { outline: 2px solid var(--vert); outline-offset: 1px; }
textarea.champ { resize: vertical; font-size: 0.78rem; }
.montant { display: flex; align-items: center; gap: 0.5rem; }
.montant .champ { font-size: 1.5rem; font-weight: 700; font-stretch: 110%; font-variant-numeric: tabular-nums; }
.montant span { font-weight: 700; color: var(--encre-2); }
.conversion { font-size: 0.8rem; font-weight: 400; color: var(--encre-2); font-variant-numeric: tabular-nums; }
.action { border: 0; border-radius: 8px; padding: 0.7rem 1rem; font-weight: 700; cursor: pointer; background: var(--encre); color: var(--fond); display: flex; justify-content: center; align-items: center; gap: 0.5rem; }
.action:disabled { opacity: 0.5; cursor: not-allowed; }
.action .eclair { color: var(--jaune); }
.facture { display: grid; grid-template-columns: 112px 1fr; gap: 0.8rem; align-items: start; padding-top: 0.85rem; border-top: 1px solid var(--trait); }
.qr { width: 112px; height: 112px; background: #fff; padding: 4px; border-radius: 6px; border: 1px solid var(--trait); }
.qr img { width: 100%; height: 100%; display: block; }
.facture dl { margin: 0; display: grid; gap: 0.15rem; font-size: 0.82rem; min-width: 0; }
.facture dt { color: var(--encre-2); font-size: 0.72rem; }
.facture dd { margin: 0 0 0.25rem; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.statut { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; font-size: 0.78rem; border-radius: 99px; padding: 0.1rem 0.55rem; }
.statut.attente { background: var(--jaune-doux); }
.statut.paye { background: var(--vert-doux); color: var(--vert); }
.statut.refus { background: var(--rouge-doux); color: var(--rouge); }
.bolt { font-family: var(--mono); font-size: 0.72rem; word-break: break-all; color: var(--encre-2); background: var(--surface-2); border-radius: 6px; padding: 0.45rem 0.55rem; grid-column: 1 / -1; max-height: 6.5em; overflow-y: auto; }
.resume { margin: 0; border-left: 3px solid var(--jaune); padding: 0.1rem 0 0.1rem 0.75rem; display: grid; grid-template-columns: auto 1fr; gap: 0.15rem 0.8rem; font-size: 0.84rem; }
.resume dt { color: var(--encre-2); }
.resume dd { margin: 0; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
.erreur { margin: 0; color: var(--rouge); background: var(--rouge-doux); border-radius: 8px; padding: 0.5rem 0.7rem; font-size: 0.84rem; font-weight: 600; }
.succes { margin: 0; color: var(--vert); background: var(--vert-doux); border-radius: 8px; padding: 0.5rem 0.7rem; font-size: 0.84rem; font-weight: 700; }
.attaques { border-top: 1px solid var(--trait); padding-top: 0.85rem; display: flex; flex-direction: column; gap: 0.5rem; }
.attaques p { margin: 0; font-size: 0.8rem; color: var(--encre-2); }
.attaque { text-align: left; border: 1px solid var(--trait); background: var(--surface); border-radius: 8px; padding: 0.55rem 0.7rem; cursor: pointer; display: flex; flex-direction: column; gap: 0.1rem; }
.attaque strong { font-size: 0.86rem; }
.attaque small { color: var(--encre-2); }
.attaque:hover:not(:disabled) { border-color: var(--rouge); }
.attaque:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── Le fil : diagramme de séquence ── */
.scene-defile { overflow-x: auto; }
.couloirs { display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; padding: 0.8rem 0 0.6rem; border-bottom: 1px solid var(--trait); min-width: 540px; }
.couloir { display: flex; flex-direction: column; align-items: center; gap: 0.1rem; }
.couloir strong { font-size: 0.86rem; font-stretch: 110%; }
.couloir small { font-size: 0.72rem; color: var(--encre-2); }
.couloir::before { content: ''; width: 10px; height: 10px; border-radius: 3px; background: var(--c); margin-bottom: 0.2rem; }
.scene { /* les 3 lignes de vie, qui défilent avec le contenu */
  position: relative; min-width: 540px; padding: 0.4rem 0 1rem; min-height: 420px; max-height: 70vh; overflow-y: auto;
  background-attachment: local;
  background:
    linear-gradient(var(--trait), var(--trait)) calc(100% / 6) 0 / 1px 100% no-repeat,
    linear-gradient(var(--trait), var(--trait)) 50% 0 / 1px 100% no-repeat,
    linear-gradient(var(--trait), var(--trait)) calc(500% / 6) 0 / 1px 100% no-repeat;
  background-attachment: local;
}
.etape { position: relative; height: 3.5rem; display: block; width: 100%; border: 0; background: transparent; cursor: pointer; padding: 0; animation: entree 0.35s ease-out both; }
.etape:hover .trait-fleche, .etape[aria-current="true"] .trait-fleche { height: 3px; }
.etape[aria-current="true"] { background: linear-gradient(90deg, transparent, var(--vert-doux) 15%, var(--vert-doux) 85%, transparent); }
.etape:focus-visible { outline: 2px solid var(--vert); outline-offset: -2px; }
.num { position: absolute; left: 6px; top: 50%; transform: translateY(-50%); font-family: var(--mono); font-size: 0.7rem; color: var(--encre-2); }
.simule { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); font-size: 0.64rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--encre-2); border: 1px dashed var(--trait); border-radius: 4px; padding: 0 0.3rem; }
.fleche { position: absolute; top: 2.4rem; }
.trait-fleche { display: block; height: 2px; background: var(--c); position: relative; transition: height 0.12s; }
.fleche.droite .trait-fleche::after, .fleche.gauche .trait-fleche::after { content: ''; position: absolute; top: 50%; border: 6px solid transparent; transform: translateY(-50%); }
.fleche.droite .trait-fleche::after { right: -2px; border-left: 9px solid var(--c); border-right: 0; }
.fleche.gauche .trait-fleche::after { left: -2px; border-right: 9px solid var(--c); border-left: 0; }
.fleche.reponse .trait-fleche { background: repeating-linear-gradient(90deg, var(--c) 0 6px, transparent 6px 10px); }
.legende-fleche { position: absolute; top: 0.9rem; text-align: center; font-size: 0.76rem; font-family: var(--mono); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 0.4rem; }
.ko .legende-fleche { color: var(--rouge); }
.boucle { position: absolute; top: 0.8rem; height: 2rem; display: flex; align-items: center; gap: 0.3rem; padding: 0 0.6rem; border-radius: 6px; font-size: 0.76rem; font-family: var(--mono); border: 1.5px solid var(--c); background: var(--surface); transform: translateX(-50%); white-space: nowrap; max-width: 64%; overflow: hidden; text-overflow: ellipsis; }
.evenement { position: absolute; top: 0.8rem; height: 2rem; display: flex; align-items: center; padding: 0 0.7rem; border-radius: 99px; font-size: 0.76rem; font-weight: 700; background: var(--jaune); color: #13201a; transform: translateX(-50%); white-space: nowrap; }
.attente-ligne { display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 2.5rem; font-size: 0.8rem; color: var(--encre-2); position: relative; }
.attente-ligne i { width: 7px; height: 7px; border-radius: 50%; background: var(--jaune); animation: pulse 1s infinite ease-in-out; }
@keyframes pulse { 50% { opacity: 0.25; transform: scale(0.7); } }
@keyframes entree { from { opacity: 0.2; transform: translateY(-6px); } }
.fil footer { display: flex; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; padding: 0.6rem 1rem; border-top: 1px solid var(--trait); font-size: 0.78rem; color: var(--encre-2); }
.fil footer span { display: inline-flex; align-items: center; gap: 0.35rem; }
.mini { display: inline-block; width: 22px; height: 2px; background: var(--encre-2); }
.mini.tirets { background: repeating-linear-gradient(90deg, var(--encre-2) 0 4px, transparent 4px 7px); }
.vide { color: var(--encre-2); font-size: 0.9rem; text-align: center; padding: 3rem 1rem; margin: 0; position: relative; }

/* ── Inspecteur ── */
.inspecteur .corps { gap: 0.9rem; }
.sens { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; font-weight: 600; flex-wrap: wrap; }
.sens .c { display: inline-flex; align-items: center; gap: 0.3rem; }
.sens .c::before { content: ''; width: 8px; height: 8px; border-radius: 2px; background: var(--c); }
.inspecteur h3 { font-size: 1.15rem; font-stretch: 112%; font-weight: 750; }
.explication { margin: 0; font-size: 0.9rem; max-width: 60ch; }
.note-simule { margin: 0; font-size: 0.8rem; color: var(--encre-2); border: 1px dashed var(--trait); border-radius: 8px; padding: 0.4rem 0.6rem; }
.requete { border: 1px solid var(--trait); border-radius: 8px; overflow: hidden; }
.ligne-http { font-family: var(--mono); font-size: 0.8rem; padding: 0.5rem 0.7rem; background: var(--surface-2); border-bottom: 1px solid var(--trait); display: flex; gap: 0.5rem; flex-wrap: wrap; overflow-wrap: anywhere; }
.ligne-http b { color: var(--vert); }
.entetes { margin: 0; padding: 0.5rem 0.7rem; display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 0.15rem 0.7rem; font-family: var(--mono); font-size: 0.74rem; border-bottom: 1px solid var(--trait); }
.entetes dt { color: var(--encre-2); }
.entetes dd { margin: 0; overflow-wrap: anywhere; }
.entetes dd.secret { background: var(--jaune-doux); border-radius: 3px; padding: 0 0.2rem; }
pre.json { margin: 0; padding: 0.6rem 0.7rem; font-family: var(--mono); font-size: 0.76rem; line-height: 1.55; overflow-x: auto; white-space: pre; }
.json :deep(.k) { color: var(--nav); }
.json :deep(.s) { color: var(--vert); }
.json :deep(.n) { color: var(--rouge); }
.verifs { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.86rem; }
.verifs li { display: grid; grid-template-columns: 1.3rem 1fr; gap: 0.3rem; align-items: start; }
.verifs .o { color: var(--vert); font-weight: 800; }
.verifs .x { color: var(--rouge); font-weight: 800; }
.verifs small { display: block; color: var(--encre-2); font-family: var(--mono); font-size: 0.72rem; overflow-wrap: anywhere; }
.anatomie-bloc { display: flex; flex-direction: column; gap: 0.4rem; }
.astuce { margin: 0; font-size: 0.82rem; color: var(--encre-2); border-top: 1px solid var(--trait); padding-top: 0.75rem; }

@media (prefers-reduced-motion: reduce) { .etape, .attente-ligne i { animation: none; } }
</style>
