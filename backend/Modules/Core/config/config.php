<?php

return [
    // Code exigé (en-tête x-admin-token) pour le payout, le fil, le journal
    // et le rejeu. Vide = tout ouvert (atelier en simulation).
    'admin_token' => env('ADMIN_TOKEN', ''),
];
