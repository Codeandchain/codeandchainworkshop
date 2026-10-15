<script setup>
// ════════════════════════════════════════════════════════════════
//  Démo Bitnob Lightning — UNE page, 3 blocs :
//   1. FACTURE : créer une facture Lightning (QR + BOLT11) et voir
//      son statut passer à "payé" (polling + webhook)
//   2. PAYOUT  : payer une facture Lightning (envoyer des sats)
//   3. JOURNAL : ce que le serveur a vu — webhooks reçus, payouts…
//  Tout le code Bitnob est côté serveur (src/lib/bitnob.js) :
//  le navigateur n'appelle jamais Bitnob directement.
// ════════════════════════════════════════════════════════════════
import { ref, onMounted, onUnmounted, computed } from 'vue'
import QRCode from 'qrcode'
// Les icônes viennent de Lucide (@lucide/vue) : du SVG léger, cohérent, sans emoji.
import {
  Zap, FlaskConical, CreditCard, Receipt, Copy, Check, PartyPopper,
  AlertTriangle, Send, Lightbulb, ShieldCheck, Clock, Hourglass,
  XCircle, ScrollText, ArrowDown, Link, Microscope,
} from '@lucide/vue'
import AnatomieBolt11 from '../components/AnatomieBolt11.vue'
import { decoderBolt11 } from '../lib/bolt11.js'
import { api, codeAdmin } from '../lib/api.js'

// ── Infos générales (mode simulation ou vrai Bitnob) ───────────
const info = ref(null)

// ── Bloc 1 : facture ───────────────────────────────────────────
const montant = ref(100)
const description = ref('Café au teranga')
const facture = ref(null) // la facture créée (id, paymentRequest, status…)
const qr = ref('')
const statut = ref(null) // statut en direct de NOTRE facture
const copie = ref(false)
const enCoursFacture = ref(false)
const erreurFacture = ref(null)

// ── Bloc 2 : payout ────────────────────────────────────────────
const bolt11 = ref('')
const resultatPayout = ref(null)
const enCoursPayout = ref(false)
const erreurPayout = ref(null)
// On décode la facture collée AVANT de payer : illisible ou expirée → on bloque
const bolt11Payable = computed(() => {
  try { return !decoderBolt11(bolt11.value).expiree } catch { return false }
})

// ── Bloc 3 : journal du serveur ────────────────────────────────
const journal = ref([])
const journalVerrouille = ref(false) // ADMIN_TOKEN défini et code absent/faux

// Statut affiché en badge coloré, avec son icône Lucide
const badgeStatut = computed(() => ({
  pending: ['En attente', 'warn', Clock],
  paid: ['Payé', 'ok', Check],
  expired: ['Expiré', 'ko', Hourglass],
  failed: ['Échoué', 'ko', XCircle],
}[statut.value] || ['—', '', Clock]))

// ── Cycle de vie : infos + journal + statut de la facture ──────
let minuteurs = []
onMounted(async () => {
  info.value = await api('/api/info').then((r) => r.json())
  rafraichirJournal()
  minuteurs.push(setInterval(rafraichirJournal, 1500))
})
onUnmounted(() => minuteurs.forEach(clearInterval))

function rafraichirJournal() {
  api('/api/journal').then((r) => {
    journalVerrouille.value = r.status === 401
    return r.ok ? r.json() : { journal: [] }
  }).then((d) => { journal.value = d.journal || [] }).catch(() => {})
}

// Polling du statut : tant que la facture est "pending", on redemande.
let minuteurFacture = null
function surveillerFacture(id) {
  clearInterval(minuteurFacture)
  const interroger = async () => {
    try {
      const r = await api(`/api/invoices/${id}`)
      const d = await r.json()
      statut.value = d.status
      facture.value = { ...facture.value, ...d }
      if (d.status !== 'pending') clearInterval(minuteurFacture) // stop
    } catch { /* le prochain tick réessaiera */ }
  }
  interroger()
  minuteurFacture = setInterval(interroger, 1000)
}

// ── Actions ────────────────────────────────────────────────────
async function creerFacture() {
  erreurFacture.value = null
  enCoursFacture.value = true
  try {
    const r = await api('/api/invoices', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount: Number(montant.value), description: description.value }),
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.erreur || `HTTP ${r.status}`)
    facture.value = d
    statut.value = d.status
    qr.value = await QRCode.toDataURL(d.paymentRequest, { margin: 1, width: 240, color: { dark: '#1b1b13', light: '#ffffff' } })
    surveillerFacture(d.id)
  } catch (e) { erreurFacture.value = String(e.message || e) }
  finally { enCoursFacture.value = false }
}

async function copierBolt11() {
  await navigator.clipboard.writeText(facture.value.paymentRequest)
  copie.value = true
  setTimeout(() => (copie.value = false), 1600)
}

async function payerFacture() {
  erreurPayout.value = null
  resultatPayout.value = null
  enCoursPayout.value = true
  try {
    const r = await api('/api/pay', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ invoice: bolt11.value }),
    })
    const d = await r.json()
    if (!r.ok) throw new Error(d.erreur || `HTTP ${r.status}`)
    resultatPayout.value = d
  } catch (e) { erreurPayout.value = String(e.message || e) }
  finally { enCoursPayout.value = false }
}

const heure = (at) => new Date(at).toLocaleTimeString('fr-FR')
</script>

<template>
  <div class="page">
    <!-- ═══ Bandeau ═══ -->
    <header class="haut">
      <h1><Zap :size="28" class="titre-icone" /> Démo Bitnob Lightning</h1>
      <p class="sous">Invoice → QR → Webhook → Payout : tout le cycle, en une page · Code &amp; Chain Dakar</p>
      <p v-if="info" class="modes">
        <span class="badge" :class="info.mock ? 'warn' : 'ok'">
          <FlaskConical v-if="info.mock" :size="14" />
          <CreditCard v-else :size="14" />
          {{ info.mock ? 'Mode simulation (aucun argent réel)' : `Bitnob réel — ${info.baseUrl}` }}
        </span>
        <span class="badge" :class="info.webhooksConfigures ? 'ok' : 'ko'">
          <Link :size="14" /> Webhook : {{ info.webhookUrl }}
        </span>
        <input v-if="info.protege" v-model.trim="codeAdmin" class="saisie code-admin" type="password"
          autocomplete="off" placeholder="Code admin" aria-label="Code admin (ADMIN_TOKEN)" />
      </p>
    </header>
    <div v-if="info?.mock" class="bandeau-mock">
      Les factures sont « payées » automatiquement ~2 s après leur création — parfait pour démontrer le webhook.
    </div>

    <main class="grille">
      <!-- ═══ 1. FACTURE LIGHTNING ═══ -->
      <section class="carte anim">
        <h2><Receipt :size="22" class="titre-icone" /> Créer une facture Lightning</h2>
        <p class="aide">Le serveur appelle <code>POST /api/lightning/invoices</code> chez Bitnob, et t'affiche le QR à scanner.</p>
        <label>Montant (sats)
          <input v-model.number="montant" class="saisie" type="number" min="1" max="20000000" />
        </label>
        <label>Description
          <input v-model="description" class="saisie" maxlength="120" />
        </label>
        <button class="btn" :disabled="enCoursFacture" @click="creerFacture">
          <Receipt :size="18" /> {{ enCoursFacture ? 'Création…' : 'Créer la facture' }}
        </button>
        <p v-if="erreurFacture" class="erreur"><AlertTriangle :size="16" class="titre-icone" /> {{ erreurFacture }}</p>

        <!-- Résultat : QR + statut + BOLT11 -->
        <div v-if="facture" class="facture anim">
          <p class="ligne">
            <strong>{{ facture.montantSats }} sats</strong>
            <span class="badge" :class="badgeStatut[1]">
              <component :is="badgeStatut[2]" :size="14" /> {{ badgeStatut[0] }}
            </span>
          </p>
          <img v-if="qr" :src="qr" alt="QR code de la facture Lightning" class="qr" />
          <p class="aide centre">
            Scanne avec n'importe quel wallet Lightning — ou laisse la simulation faire (~2 s).
          </p>
          <textarea class="zone" readonly :value="facture.paymentRequest" rows="3"></textarea>
          <button class="btn btn-vert" @click="copierBolt11">
            <Check v-if="copie" :size="18" /><Copy v-else :size="18" /> {{ copie ? 'Copié' : 'Copier le BOLT11' }}
          </button>
          <details class="dissection" open>
            <summary><Microscope :size="16" class="titre-icone" /> Décortiquer cette facture</summary>
            <p class="aide">Décodée ici, dans ton navigateur : une facture BOLT11 se lit sans rien demander à personne.</p>
            <AnatomieBolt11 :facture="facture.paymentRequest" :preimage="statut === 'paid' ? facture.preimage : ''" />
          </details>
          <p v-if="statut === 'paid'" class="succes anim">
            <PartyPopper :size="18" class="titre-icone" /> Payé ! preimage :
            <code>{{ (facture.preimage || '').slice(0, 24) }}…</code><br />
            <small>Le serveur l'a su par le WEBHOOK (voir le journal <ArrowDown :size="14" />), pas par le navigateur.</small>
          </p>
        </div>
      </section>

      <!-- ═══ 2. PAYOUT ═══ -->
      <section class="carte anim">
        <h2><Send :size="22" class="titre-icone" /> Payer une facture (payout)</h2>
        <p class="aide">Envoyer des sats à une facture Lightning — le chemin sortant, via <code>POST /api/lightning/pay</code>.</p>
        <label>Facture BOLT11 à payer
          <textarea v-model="bolt11" class="zone" rows="4" placeholder="lnbc1..."></textarea>
        </label>
        <div v-if="bolt11.trim()" class="avant-payer">
          <p class="aide"><Microscope :size="16" class="titre-icone" /> <strong>Avant de payer, on lit la facture :</strong> combien, à qui, jusqu'à quand.</p>
          <AnatomieBolt11 :facture="bolt11" compact />
        </div>
        <button class="btn btn-rouge" :disabled="enCoursPayout || !bolt11Payable" @click="payerFacture">
          <Send :size="18" /> {{ enCoursPayout ? 'Envoi sur le réseau…' : 'Envoyer le paiement' }}
        </button>
        <p v-if="erreurPayout" class="erreur"><AlertTriangle :size="16" class="titre-icone" /> {{ erreurPayout }}</p>
        <div v-if="resultatPayout" class="facture anim">
          <p class="ligne">
            <span class="badge ok">{{ resultatPayout.status }}</span>
            <span v-if="resultatPayout.montantSats != null">{{ resultatPayout.montantSats }} sats</span>
            <span>frais : {{ resultatPayout.feeSats }} sat</span>
          </p>
          <p v-if="resultatPayout.paymentHash" class="aide">payment_hash payé : <code>{{ resultatPayout.paymentHash.slice(0, 16) }}…</code></p>
          <p class="aide"><Lightbulb :size="16" class="titre-icone" /> Astuce pédagogique : colle le BOLT11 créé dans le bloc 1 — le payout « simulation » l'acceptera.</p>
        </div>
      </section>

      <!-- ═══ 3. JOURNAL ═══ -->
      <section class="carte anim large">
        <h2><ScrollText :size="22" class="titre-icone" /> Journal du serveur <span class="aide">(webhooks reçus, payouts, factures)</span></h2>
        <ul class="journal">
          <li v-for="(e, i) in journal" :key="i" class="anim">
            <span class="heure">{{ heure(e.at) }}</span>
            <span class="badge" :class="e.type === 'webhook' ? 'ok' : e.ok ? '' : 'ko'">{{ e.type }}</span>
            <strong>{{ e.titre }}</strong>
            <span class="detail">{{ e.detail }}</span>
          </li>
          <li v-if="journalVerrouille" class="vide">Journal protégé : entre le code admin en haut de la page</li>
          <li v-else-if="!journal.length" class="vide">Rien encore — crée une facture dans le bloc 1</li>
        </ul>
        <p class="aide">
          <ShieldCheck :size="16" class="titre-icone" /> Chaque webhook est accepté uniquement si la signature
          <code>x-bitnob-signature</code> (HMAC-SHA512 du corps brut) est valide, et un <code>event_id</code>
          déjà traité est ignoré (idempotence).
        </p>
      </section>
    </main>
  </div>
</template>

<style scoped>
.page { max-width: 1120px; margin: 0 auto; padding: 1.4rem 1rem 4rem; }

/* ── En-tête ── */
.haut { position: relative; text-align: center; padding: 0.5rem 0 0.2rem; }
.haut h1 { color: var(--encre); font-size: clamp(1.7rem, 4vw, 2.4rem); margin: 0 0 0.4rem; }
.haut h1 .titre-icone {
  vertical-align: -0.18em; color: var(--jaune);
  filter: drop-shadow(0 2px 6px rgba(242, 183, 5, 0.5));
}
.sous { margin: 0 auto 0.8rem; color: var(--encre-doux); font-size: 1.02rem; max-width: 56ch; }
.code-admin { width: 11rem; padding: 0.25rem 0.7rem; font-size: 0.85rem; border-radius: 999px; }
.modes { display: inline-flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center; margin: 0; }

.grille { display: grid; grid-template-columns: 1fr 1fr; gap: 1.15rem; margin-top: 1.4rem; align-items: start; }
.large { grid-column: 1 / -1; }
@media (max-width: 760px) { .grille { grid-template-columns: 1fr; } }

/* ── Titres de section ── */
.carte h2 {
  display: flex; align-items: center; gap: 0.5rem;
  font-size: 1.22rem; color: var(--encre);
  padding-top: 0.35rem;
}
.carte h2 .lucide { color: var(--vert); }
.carte h2 span.aide { margin-left: 0.15rem; }

label { display: block; font-weight: 700; margin: 0.7rem 0 0.28rem; font-size: 0.9rem; color: var(--encre); }
.aide { font-size: 0.86rem; color: var(--encre-doux); line-height: 1.5; margin: 0 0 0.4rem; }
.aide.centre { text-align: center; }
code { background: rgba(0, 133, 63, 0.09); color: #06654a; padding: 0.1rem 0.38rem; border-radius: 6px; font-size: 0.85em; font-weight: 600; }
.erreur { display: flex; align-items: center; gap: 0.4rem; color: var(--rouge); font-weight: 700; background: rgba(227, 27, 35, 0.08); padding: 0.5rem 0.7rem; border-radius: 10px; margin: 0.4rem 0 0; }

/* ── Résultat facture ── */
.facture { margin-top: 1rem; border-top: 1px dashed var(--bordure); padding-top: 0.9rem; display: flex; flex-direction: column; gap: 0.7rem; }
.ligne { display: flex; align-items: center; gap: 0.6rem; margin: 0; flex-wrap: wrap; font-size: 1.05rem; }
.qr {
  width: 224px; max-width: 100%; align-self: center; margin: 0.2rem 0;
  border-radius: 16px; background: #ffffff; padding: 10px;
  border: 1px solid var(--bordure);
  box-shadow: var(--ombre-douce);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.qr:hover { transform: scale(1.03); box-shadow: var(--ombre-levee); }
.succes {
  display: flex; align-items: center; flex-wrap: wrap; gap: 0.3rem;
  background: linear-gradient(135deg, rgba(0, 133, 63, 0.12), rgba(10, 163, 79, 0.08));
  border: 1px solid rgba(0, 133, 63, 0.22);
  color: var(--encre); border-radius: 12px; padding: 0.7rem 0.85rem; font-weight: 700; margin: 0;
}
.succes .titre-icone { color: var(--vert); }

/* ── Anatomie BOLT11 ── */
.dissection { border: 1px solid var(--bordure); border-radius: 12px; padding: 0.6rem 0.8rem; background: #fffdf8; }
.dissection summary { cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 0.35rem; }
.dissection[open] summary { margin-bottom: 0.5rem; }
.avant-payer { margin: 0.7rem 0; border-left: 3px solid var(--jaune); padding: 0.2rem 0 0.2rem 0.8rem; }

/* ── Journal ── */
.journal { list-style: none; margin: 0.4rem 0 0.8rem; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; max-height: 340px; overflow-y: auto; padding-right: 0.2rem; }
.journal li { display: flex; gap: 0.6rem; align-items: baseline; padding: 0.5rem 0.65rem; border-radius: 10px; background: rgba(27, 27, 19, 0.035); border: 1px solid transparent; flex-wrap: wrap; transition: border-color 0.15s ease, background 0.15s ease; }
.journal li:hover { border-color: var(--bordure); background: rgba(27, 27, 19, 0.05); }
.heure { font-family: 'SF Mono', ui-monospace, monospace; font-size: 0.76rem; color: var(--encre-doux); }
.detail { color: var(--encre-doux); font-size: 0.88rem; }
.vide { color: var(--encre-doux); font-style: italic; justify-content: center; padding: 1.2rem; }
</style>


