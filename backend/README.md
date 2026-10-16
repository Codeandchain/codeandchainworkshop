# API Laravel — Démo Bitnob Lightning

L'API de la démo (Laravel 12 + SQLite), pensée pour un hébergement mutualisé.
Installation, déploiement et explications : voir le [README principal](../README.md).

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan test          # 11 tests (Modules/*/Tests) : BOLT11, cycle complet, sécurité
```
