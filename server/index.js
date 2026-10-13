// ════════════════════════════════════════════════════════════════
//  POINT D'ENTRÉE — `npm run dev` (avec Vite) ou `npm start`
// ════════════════════════════════════════════════════════════════

import app from './app.js'
import { expirerFactures } from './registre.js'
import { configBitnob } from '../src/lib/bitnob.js'

const PORT = Number(process.env.PORT || 3000)

app.listen(PORT, () => {
  const cfg = configBitnob()
  console.log('')
  console.log('  Démo Bitnob Lightning — Code & Chain Dakar')
  console.log('  ─────────────────────────────────────────────')
  console.log(`  Interface :  http://localhost:${PORT}`)
  console.log(`  Webhook :  http://localhost:${PORT}/api/webhook/bitnob`)
  console.log(`  Paiements :  ${cfg.mock ? 'MODE SIMULATION (aucun argent réel)' : `Bitnob (${cfg.baseUrl})`}`)
  console.log('')
})

// Tickeuse : fait expirer les factures non payées (toutes les 10 s).
setInterval(expirerFactures, 10_000)
