<?php

namespace Modules\Paiements\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Paiements\App\Services\Interfaces\WebhookServiceInterface;

class WebhookController extends Controller
{
    /**
     * WebhookController constructor.
     *
     * @param WebhookServiceInterface $webhookService
     */
    public function __construct(protected WebhookServiceInterface $webhookService)
    {
    }

    /**
     * POST /api/webhook/bitnob — l'URL à donner à Bitnob.
     * La signature porte sur le CORPS BRUT ($request->getContent()) : le
     * JSON décodé puis ré-encodé n'aurait plus les mêmes octets.
     */
    public function __invoke(Request $request)
    {
        [$code, $reponse] = $this->webhookService->traiter($request->getContent(), $request->header('x-bitnob-signature'));

        return response()->json($reponse, $code);
    }
}
