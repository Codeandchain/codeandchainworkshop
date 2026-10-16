<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\App\Http\Middleware\ExigerCodeAdmin;
use Modules\Paiements\App\Http\Controllers\FactureController;
use Modules\Paiements\App\Http\Controllers\PayoutController;
use Modules\Paiements\App\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| API Routes — Paiements
|--------------------------------------------------------------------------
|
| Publiques : la création de facture (on reçoit de l'argent), son statut,
| et le webhook (protégé par sa signature HMAC). Le payout exige le code
| admin quand ADMIN_TOKEN est défini.
|
*/

Route::post('/invoices', [FactureController::class, 'store']);
Route::get('/invoices/{id}', [FactureController::class, 'show']);
Route::post('/webhook/bitnob', WebhookController::class);

Route::middleware(ExigerCodeAdmin::class)->group(function () {
    Route::post('/pay', PayoutController::class);
});
