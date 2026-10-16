<?php

return [
    // false = coupe le fil des échanges et le rejeu (vraie production)
    'outils_demo' => filter_var(env('DEMO_TOOLS', true), FILTER_VALIDATE_BOOL),
];
