#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════
#  PAQUET POUR HÉBERGEMENT MUTUALISÉ — npm run paquet
#
#  Fabrique deploiement/bitnob-demo-AAAAMMJJ-HHMM.zip, prêt à envoyer
#  par FTP / gestionnaire de fichiers, SANS avoir besoin de SSH chez
#  l'hébergeur :
#   • l'interface Vue compilée (backend/public/spa)
#   • vendor/ sans les dépendances de dev
#   • une base SQLite vide, déjà migrée
#   • un .env de PRODUCTION : APP_KEY neuve, APP_DEBUG=false, simulation
#     activée, et un ADMIN_TOKEN aléatoire (affiché à la fin)
#  Ton backend/.env local n'est jamais copié.
# ════════════════════════════════════════════════════════════════
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
SORTIE="$RACINE/deploiement"
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT
NOM="bitnob-demo-$(date +%Y%m%d-%H%M)"
CIBLE="$TRAVAIL/$NOM"

echo "1/5  Compilation de l'interface…"
(cd "$RACINE" && npm run build --silent)

echo "2/5  Copie de l'API Laravel (sans secrets ni fichiers locaux)…"
rsync -a \
  --exclude vendor --exclude node_modules --exclude .env --exclude '.env.*.local' \
  --exclude 'database/*.sqlite*' --exclude 'storage/logs/*.log' \
  --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*.php' --exclude 'bootstrap/cache/*.php' \
  --exclude tests --exclude phpunit.xml --exclude .phpunit.result.cache \
  "$RACINE/backend/" "$CIBLE/"

echo "3/5  Dépendances PHP de production…"
composer install --working-dir="$CIBLE" --no-dev --optimize-autoloader --no-interaction --quiet

echo "4/5  .env de production + base SQLite…"
CLE="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
JETON="$(php -r 'echo bin2hex(random_bytes(24));')"
sed \
  -e "s#^APP_ENV=.*#APP_ENV=production#" \
  -e "s#^APP_KEY=.*#APP_KEY=$CLE#" \
  -e "s#^APP_DEBUG=.*#APP_DEBUG=false#" \
  -e "s#^APP_URL=.*#APP_URL=https://ton-domaine.example#" \
  -e "s#^LOG_LEVEL=.*#LOG_LEVEL=error#" \
  -e "s#^ADMIN_TOKEN=.*#ADMIN_TOKEN=$JETON#" \
  "$CIBLE/.env.example" > "$CIBLE/.env"
touch "$CIBLE/database/database.sqlite"
(cd "$CIBLE" && php artisan migrate --force --no-interaction --quiet)

echo "5/5  Archive…"
mkdir -p "$SORTIE"
(cd "$TRAVAIL" && zip -qr "$SORTIE/$NOM.zip" "$NOM")

cat <<EOF

  Paquet prêt : deploiement/$NOM.zip

  Code admin (ADMIN_TOKEN) de ce paquet :  $JETON
  → l'interface le demande pour le fil des échanges et le payout.

  Chez l'hébergeur :
   1. décompresse l'archive (dans public_html, ou à côté si tu peux
      pointer le domaine sur le dossier public/)
   2. dans .env : remplace APP_URL par ton domaine (et, plus tard,
      MOCK_PAYMENTS=false + tes clés Bitnob)
   3. donne les droits d'écriture à storage/, bootstrap/cache/ et database/
   Détails : README, section « Déployer sur un hébergement mutualisé ».
EOF
