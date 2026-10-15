<script setup>
// ════════════════════════════════════════════════════════════════
//  Deux interfaces, la même API :
//   • Banc      (#banc)      → le fil des échanges + l'inspecteur
//   • Classique (#classique) → les 3 cartes : facture, payout, journal
//  On passe de l'une à l'autre avec le sélecteur ou l'URL (#…).
// ════════════════════════════════════════════════════════════════
import { ref, onMounted, onUnmounted } from 'vue'
import Banc from './views/Banc.vue'
import Classique from './views/Classique.vue'

const lireHash = () => (location.hash === '#classique' ? 'classique' : 'banc')
const vue = ref(lireHash())
const surHash = () => { vue.value = lireHash() }
onMounted(() => window.addEventListener('hashchange', surHash))
onUnmounted(() => window.removeEventListener('hashchange', surHash))
</script>

<template>
  <nav class="selecteur" aria-label="Choisir l'interface">
    <a href="#banc" :aria-current="vue === 'banc' ? 'page' : undefined">Banc</a>
    <a href="#classique" :aria-current="vue === 'classique' ? 'page' : undefined">Classique</a>
  </nav>
  <Banc v-if="vue === 'banc'" />
  <Classique v-else />
</template>

<style scoped>
/* Petit sélecteur flottant, neutre pour aller avec les deux interfaces */
.selecteur {
  position: fixed; z-index: 10;
  right: 16px; bottom: calc(16px + env(safe-area-inset-bottom, 0px));
  display: flex; gap: 2px; padding: 3px; border-radius: 99px;
  background: rgba(19, 32, 26, 0.88); backdrop-filter: blur(6px);
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
  font: 600 0.8rem/1 system-ui, sans-serif;
}
.selecteur a { color: #e4ece6; text-decoration: none; padding: 0.45rem 0.85rem; border-radius: 99px; }
.selecteur a[aria-current="page"] { background: #f2c200; color: #13201a; }
.selecteur a:focus-visible { outline: 2px solid #f2c200; outline-offset: 2px; }
</style>
