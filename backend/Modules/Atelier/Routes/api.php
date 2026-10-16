<?php

use Illuminate\Support\Facades\Route;
use Modules\Atelier\App\Http\Controllers\AtelierController;
use Modules\Atelier\App\Http\Middleware\ExigerOutilsDemo;
use Modules\Core\App\Http\Middleware\ExigerCodeAdmin;

/*
|--------------------------------------------------------------------------
| API Routes — Atelier
|--------------------------------------------------------------------------
|
| /info est public (l'interface en a besoin pour s'afficher). Le journal,
| le fil et le rejeu exigent le code admin quand ADMIN_TOKEN est défini ;
| DEMO_TOOLS=false coupe le fil et le rejeu.
|
*/

Route::get('/info', [AtelierController::class, 'info']);

Route::middleware(ExigerCodeAdmin::class)->group(function () {
    Route::get('/journal', [AtelierController::class, 'journal']);

    Route::middleware(ExigerOutilsDemo::class)->group(function () {
        Route::get('/echanges', [AtelierController::class, 'echanges']);
        Route::post('/demo/rejouer-webhook', [AtelierController::class, 'rejouerWebhook']);
    });
});
