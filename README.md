# Démo Bitnob Lightning

Une **interface unique** pour montrer tout le cycle d'un paiement Lightning avec
l'API [Bitnob](https://www.bitnob.dev) — pensé pour le workshop **Code & Chain Dakar** :

> **Invoice → QR → Webhook → Payout**

Trois blocs sur une page Vue 3 :

| Bloc | Ce qu'on montre | Endpoint Bitnob utilisé |
|------|-----------------|--------------------------|
| 1. Facture | Créer une facture Lightning, l'afficher en QR / BOLT11, la voir passer à « payé » | `POST /api/lightning/invoices`, `GET /api/lightning/invoices/:id` |
| 2. Payout | Envoyer des sats à une facture Lightning (le chemin sortant) | `POST /api/lightning/pay` |
| 3. Journal | Les webhooks reçus : signature vérifiée, retries ignorés | `POST /api/webhook/bitnob` (notre route) |

## Arborescence

```
├── index.html → point d'entrée Vite
├── vite.config.js → proxy /api → serveur Express en dev
├── vercel.json → déploiement Vercel (SPA + fonction /api)
├── api/index.js → enveloppe serverless de l'app Express
├── server/
│  ├── index.js → lance l'écoute HTTP + expiration factures
│  ├── app.js → 5 routes API + traitement webhook
│  └── registre.js → mémoire : factures, journal, dédup
├── src/
│  ├── main.js · App.vue → l'interface (une seule page)
│  ├── styles/global.css → palette Sénégal (vert/jaune/rouge/sable)
│  └── lib/bitnob.js → TOUTE l'intégration Bitnob est ici
└── scripts/smoke.mjs → test de bout en bout (mode simulation)
```

## 1. Démarrer en mode simulation (3 commandes)

```bash
npm install
cp .env.example .env  # MOCK_PAYMENTS=true par défaut : aucune clé, aucun argent
npm run dev  # API sur :3000, interface sur :5173
```

Puis ouvre `http://localhost:5173`, clique sur « Créer la facture » : ~2 s plus
tard, la facture est « payée », le webhook signé apparaît dans le journal, et tu
peux rejouer ce même BOLT11 dans le bloc payout. `npm run smoke` rejoue tout ça
automatiquement (lance d'abord `npm start` dans un autre terminal).

En mode simulation, le serveur **fabrique lui-même le webhook** que Bitnob
aurait envoyé (signé avec le même HMAC-SHA512) et le fait passer par le **même
code de vérification** : on démontre la vraie mécanique, sans argent.

## 2. Brancher la clé Bitnob (sandbox)

1. Crée un compte sur [app.bitnob.com](https://app.bitnob.com)  **Settings → API Keys** :
  tu obtiens un **Client ID** et une **Secret Key** (l'API s'authentifie par
  signature HMAC, pas par une simple clé dans un header).
2. Renseigne dans `.env` :

  ```bash
  MOCK_PAYMENTS=false
  BITNOB_CLIENT_ID=tonClientId
  BITNOB_SECRET_KEY=taSecretKey
  BITNOB_WEBHOOK_SECRET=secretDuWebhook  # renvoyé à la création du webhook
  ```

3. Redémarre : la page affiche désormais «  Bitnob réel » et les factures
  sont de vraies factures BOLT11, payables par n'importe quel wallet.

 La **sandbox** et la production partagent la même URL
(`https://api.bitnob.com`) : seule la clé change — ne mélange jamais les deux.

## 3. Déployer sur Vercel + configurer le webhook

1. Pousse le repo sur GitHub, importe-le sur [vercel.com](https://vercel.com)
  (le `vercel.json` gère la SPA + la fonction `api/`).
2. Dans les **Environment Variables** du projet Vercel, mets les mêmes vars que
  dans `.env` (dont `PUBLIC_URL=https://ton-projet.vercel.app`).
3. Dans le dashboard Bitnob, crée un webhook avec l'URL
  `https://ton-projet.vercel.app/api/webhook/bitnob` et copie son
  **signing secret** dans `BITNOB_WEBHOOK_SECRET`. L'interface affiche en haut
  l'URL exacte à configurer.

 **Limite serverless** : l'état (factures, journal) vit en mémoire, partagé
uniquement au sein d'une même instance. Sur Vercel, les instances sont
éphémères et multiples — pour une démo solide en atelier, héberge plutôt le
serveur sur une machine qui reste allumée (`npm start`, ou Render/Fly/Railway).
Pour aller loin : remplace `server/registre.js` par une base (Vercel KV, Upstash
Redis…), le reste du code ne change pas.

## 4. Le flux, en schéma

```
  NAVIGATEUR (Vue 3)  SERVEUR (Express)  BITNOB / RÉSEAU
  ─────────────────  ─────────────────  ────────────────
  "Créer facture" ──────────▶ POST /api/invoices ──────────▶ POST /api/lightning/invoices
  ◀── { id, request(BOLT11) } ◀── (201)
  QR + BOLT11 ◀──────────────┘
  │
  │  …le payeur scanne et paie avec son wallet Lightning…
  ▼  réseau Lightning
  ◀── POST /api/webhook/bitnob ── Bitnob nous appelle
  vérifie x-bitnob-signature (HMAC-SHA512) 
  ignore si event_id déjà vu (idempotence) 
  marque la facture "paid" 
  statut "payé" ◀─────────── GET /api/invoices/:id (polling, filet de sécurité)
```

## 5. Sécurité — les 3 règles du webhook

1. **Signature** : chaque POST de Bitnob porte `x-bitnob-signature`
  (HMAC-SHA512 du corps brut avec ton signing secret). On compare en
  *constant time* et on **refuse tout le reste** (fail closed).
2. **Idempotence** : Bitnob renvoie un webhook jusqu'à 3 fois en cas d'erreur.
  Chaque `event_id` n'est traité qu'une fois, et chaque facture ne crédite
  qu'une seule fois.
3. **Côté serveur uniquement** : le navigateur ne déclare jamais un paiement.
  Il affiche, le serveur vérifie.

## 6. Points à vérifier dans la doc Bitnob

Ce que les docs officielles confirment clairement, et les deux détails à
re-vérifier dans le [playground](https://www.bitnob.dev/api-reference/lightning/play-ground)
avant un usage en production :

-  Auth HMAC : en-têtes `X-Auth-Client`, `X-Auth-Timestamp`, `X-Auth-Nonce`,
  `X-Auth-Signature` sur le message `CLIENT_ID:TIMESTAMP:NONCE:PAYLOAD`.
-  `POST /api/lightning/invoices` : `{ reference, amount(sats), description, expiry }`.
-  `GET /api/lightning/invoices/:id` : `data.status ∈ pending|paid|expired|failed`,
  `preimage` = preuve du paiement.
-  Webhook : `x-bitnob-signature` = HMAC-SHA512 du corps brut ; événement type
  `btc.lightning.received.success` ; retries avec le même `event_id`.
-  **Corps exact de `POST /api/lightning/pay`** : non détaillé dans la partie
  de doc lue — on envoie `{ invoice, reference }` (à confirmer au playground).
-  **Encodage de la signature webhook** : la doc dit HMAC-SHA512 sans préciser
  hex ou base64 — le module accepte les deux.

## 7. Forke et crée ta variante

5 idées pour transformer cette démo en vrai projet :

1. **Un power-up / une action de plus** : ajoute une 4ᵉ opération côté `POST /api/invoices`
  (montant libre, description personnalisée) et affiche-la dans `App.vue`.
2. **Un autre domaine** : même cycle, autre vocabulaire — pourboire de restaurant,
  cagnotte d'équipe de foot, participation à une tontine. Tout tient dans `App.vue`.
3. **Une tontine** : chaque membre paie X sats, le serveur affiche la cagnotte et
  qui a payé ce mois-ci (`server/registre.js` suffit pour la démo).
4. **Un mur de pixels** : chaque webhook reçu allume un pixel d'une image partagée —
  c'est le webhook qui déclenche l'effet, jamais le navigateur.
5. **Un autre pays / une autre chaîne** : même démo avec une palette et des textes
  différents — tout le style est dans `src/styles/global.css`, tous les libellés
  dans `App.vue`.

