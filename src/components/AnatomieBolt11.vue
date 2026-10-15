<script setup>
// ════════════════════════════════════════════════════════════════
//  ANATOMIE D'UNE FACTURE BOLT11
//  Décode la chaîne lnbc… DANS LE NAVIGATEUR (rien n'est envoyé à
//  personne) et montre ce qu'elle contient : chaque morceau coloré,
//  puis les champs lisibles. Optionnel : si on a la preimage, on
//  vérifie la preuve de paiement sha256(preimage) == payment_hash.
// ════════════════════════════════════════════════════════════════
import { computed, ref } from 'vue'
import { ShieldCheck, ShieldAlert, KeyRound } from '@lucide/vue'
import { decoderBolt11, verifierPreimage } from '../lib/bolt11.js'

const props = defineProps({
  facture: { type: String, required: true },
  preimage: { type: String, default: '' },
  compact: { type: Boolean, default: false }, // payout : juste l'essentiel
})

const survole = ref(null) // morceau survolé : sa légende s'affiche

const resultat = computed(() => {
  try { return { d: decoderBolt11(props.facture) } }
  catch (e) { return { erreur: e.message } }
})
const d = computed(() => resultat.value.d)

const preuve = computed(() => props.preimage && d.value
  ? verifierPreimage(props.preimage, d.value.paymentHash) : null)

const date = (ms) => new Date(ms).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'medium' })
const court = (hex) => (hex ? `${hex.slice(0, 12)}…${hex.slice(-8)}` : '—')
const duree = (s) => (s >= 3600 ? `${s / 3600} h` : s >= 60 ? `${Math.round(s / 60)} min` : `${s} s`)

// Couleur de chaque morceau (les champs inconnus partagent une couleur)
const LEGENDE = {
  prefixe: 'réseau', montant: 'montant', horodatage: 'date',
  'champ-p': 'payment_hash', 'champ-d': 'description', 'champ-x': 'expiration',
  'champ-s': 'payment_secret', signature: 'signature', checksum: 'checksum',
}
const classe = (type) => (type in LEGENDE || type === 'separateur' ? type : 'champ-autre')
</script>

<template>
  <div class="anatomie">
    <p v-if="resultat.erreur" class="invalide"><ShieldAlert :size="16" /> {{ resultat.erreur }}</p>

    <template v-else>
      <!-- La chaîne, découpée et colorée morceau par morceau -->
      <template v-if="!compact">
        <p class="bolt11">
          <span
            v-for="(m, i) in d.morceaux" :key="i"
            :class="['seg', classe(m.type), { actif: survole === i }]"
            :title="m.titre"
            @mouseenter="survole = i" @mouseleave="survole = null"
          >{{ m.texte }}</span>
        </p>
        <p class="legende-survol">{{ survole !== null ? d.morceaux[survole].titre : 'Survole un morceau pour savoir ce que c\'est' }}</p>
        <p class="legende">
          <span v-for="(nom, type) in LEGENDE" :key="type" :class="['puce', type]">{{ nom }}</span>
        </p>
      </template>

      <!-- Les champs, en clair -->
      <dl class="champs">
        <dt>Réseau</dt><dd>{{ d.reseauNom }}</dd>
        <dt>Montant</dt>
        <dd><strong>{{ d.montantSats === null ? 'libre (au choix du payeur)' : `${d.montantSats} sats` }}</strong></dd>
        <template v-if="d.description !== null"><dt>Description</dt><dd>« {{ d.description }} »</dd></template>
        <dt>Expire</dt>
        <dd :class="{ ko: d.expiree }">{{ date(d.expireLe) }} ({{ duree(d.expiration) }}){{ d.expiree ? ' — EXPIRÉE' : '' }}</dd>
        <template v-if="!compact">
          <dt>Créée le</dt><dd>{{ date(d.creeLe) }}</dd>
          <dt>payment_hash</dt><dd><code>{{ court(d.paymentHash) }}</code></dd>
          <dt>payment_secret</dt><dd><code>{{ court(d.paymentSecret) }}</code></dd>
        </template>
        <dt>Nœud destinataire</dt><dd><code>{{ court(d.noeud) }}</code></dd>
        <dt>Signature</dt>
        <dd :class="d.signatureValide ? 'ok' : 'ko'">
          <ShieldCheck v-if="d.signatureValide" :size="14" /><ShieldAlert v-else :size="14" />
          {{ d.signatureValide ? 'valide — la facture vient bien de ce nœud' : 'invalide' }}
        </dd>
      </dl>

      <!-- La preuve de paiement -->
      <div v-if="preuve !== null" :class="['preuve', preuve ? 'ok' : 'ko']">
        <p><KeyRound :size="16" /> <strong>Preuve de paiement</strong></p>
        <p><code>sha256(preimage)</code> = <code>{{ court(d.paymentHash) }}</code> = <code>payment_hash</code> {{ preuve ? '✓' : '✗' }}</p>
        <p class="aide">
          {{ preuve
            ? 'Seul le destinataire connaissait la preimage, et il ne la révèle qu\'une fois payé : la détenir, c\'est le reçu.'
            : 'La preimage ne correspond pas : ce n\'est PAS une preuve de paiement de cette facture.' }}
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.anatomie { display: flex; flex-direction: column; gap: 0.55rem; }
.bolt11 {
  margin: 0; font-family: 'SF Mono', ui-monospace, monospace; font-size: 0.78rem; line-height: 1.55;
  word-break: break-all; background: var(--fond-code, #fbf9f3); border: 1px solid var(--bordure); border-radius: 10px; padding: 0.6rem 0.7rem;
}
.seg { border-radius: 3px; padding: 0 1px; cursor: help; transition: outline-color 0.1s; outline: 2px solid transparent; }
.seg.actif { outline-color: var(--encre); }
.legende-survol { margin: 0; font-size: 0.82rem; font-weight: 600; color: var(--encre); min-height: 1.2em; }
.legende { display: flex; flex-wrap: wrap; gap: 0.3rem; margin: 0; }
.puce { font-size: 0.72rem; font-weight: 600; padding: 0.1rem 0.45rem; border-radius: 99px; }

/* Une couleur par morceau — mêmes classes pour la chaîne et la légende */
.prefixe { background: rgba(0, 133, 63, 0.18); }
.montant { background: rgba(242, 183, 5, 0.45); }
.separateur { color: var(--encre-doux); }
.horodatage { background: rgba(80, 120, 220, 0.18); }
.champ-p { background: rgba(227, 27, 35, 0.16); }
.champ-d { background: rgba(160, 90, 200, 0.18); }
.champ-x { background: rgba(240, 130, 40, 0.22); }
.champ-s { background: rgba(0, 150, 160, 0.16); }
.champ-autre { background: rgba(27, 27, 19, 0.07); }
.signature { background: rgba(27, 27, 19, 0.13); }
.checksum { background: rgba(0, 133, 63, 0.35); }

.champs { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 0.8rem; margin: 0; font-size: 0.86rem; }
.champs dt { color: var(--encre-doux); font-weight: 600; }
.champs dd { margin: 0; display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; min-width: 0; overflow-wrap: anywhere; }
.ok { color: var(--vert); font-weight: 600; }
.ko { color: var(--rouge); font-weight: 700; }
.invalide { display: flex; align-items: center; gap: 0.4rem; margin: 0; color: var(--rouge); font-weight: 600; font-size: 0.88rem; }

.preuve { border-radius: 12px; padding: 0.6rem 0.8rem; display: flex; flex-direction: column; gap: 0.3rem; color: var(--encre); }
.preuve p { margin: 0; display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; font-size: 0.86rem; }
.preuve.ok { background: rgba(0, 133, 63, 0.09); border: 1px solid rgba(0, 133, 63, 0.25); }
.preuve.ko { background: rgba(227, 27, 35, 0.07); border: 1px solid rgba(227, 27, 35, 0.25); }
code { background: var(--fond-code-inline, rgba(0, 133, 63, 0.09)); color: var(--couleur-code, #06654a); padding: 0.05rem 0.35rem; border-radius: 6px; font-size: 0.85em; font-weight: 600; }
.aide { font-size: 0.82rem !important; color: var(--encre-doux); font-weight: 400; }
</style>
