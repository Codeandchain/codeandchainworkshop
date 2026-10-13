// ════════════════════════════════════════════════════════════════
//  DÉPLOIEMENT VERCEL
//  Vercel transforme chaque fichier de api/ en fonction serverless.
//  serverless-http enveloppe NOTRE app Express existante : zéro
//  réécriture, le même code tourne en local et sur Vercel.
//
//  Les fonctions serverless ne partagent pas la mémoire entre
//  instances : pour un workshop sérieux, garde un serveur unique
//  (npm start sur une machine) ou migre l'état vers Vercel KV.
//  Le README détaille la marche à suivre.
// ════════════════════════════════════════════════════════════════

import serverless from 'serverless-http'
import app from '../server/app.js'

export default serverless(app)
