<?php

/*
 * Bitnob : Dashboard → Settings → API Keys. Sandbox et production
 * partagent la même URL : seule la clé change.
 */
return [
    'base_url' => rtrim(env('BITNOB_BASE_URL', 'https://api.bitnob.com'), '/'),
    'client_id' => env('BITNOB_CLIENT_ID', ''),
    'secret_key' => env('BITNOB_SECRET_KEY', ''),
    'webhook_secret' => env('BITNOB_WEBHOOK_SECRET', ''),

    // true = aucune clé, aucun argent : le faux Bitnob répond à la place
    'mock' => filter_var(env('MOCK_PAYMENTS', true), FILTER_VALIDATE_BOOL),
    'mock_delai_ms' => (int) env('MOCK_PAYMENT_DELAY_MS', 2000),
    'expiration_s' => (int) env('INVOICE_EXPIRY_S', 600),
];
