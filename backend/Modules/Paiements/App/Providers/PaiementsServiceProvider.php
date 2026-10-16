<?php

namespace Modules\Paiements\App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Modules\Paiements\App\Http\Middleware\AvancerLeTemps;
use Modules\Paiements\App\Repositories\Eloquent\EloquentEvenementWebhookRepository;
use Modules\Paiements\App\Repositories\Eloquent\EloquentFactureRepository;
use Modules\Paiements\App\Repositories\Interfaces\EvenementWebhookRepositoryInterface;
use Modules\Paiements\App\Repositories\Interfaces\FactureRepositoryInterface;
use Modules\Paiements\App\Services\FactureService;
use Modules\Paiements\App\Services\Interfaces\FactureServiceInterface;
use Modules\Paiements\App\Services\Interfaces\PayoutServiceInterface;
use Modules\Paiements\App\Services\Interfaces\WebhookServiceInterface;
use Modules\Paiements\App\Services\PayoutService;
use Modules\Paiements\App\Services\WebhookService;

class PaiementsServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Bind Repositories
        $this->app->bind(FactureRepositoryInterface::class, EloquentFactureRepository::class);
        $this->app->bind(EvenementWebhookRepositoryInterface::class, EloquentEvenementWebhookRepository::class);

        // Bind Services
        $this->app->bind(WebhookServiceInterface::class, WebhookService::class);
        $this->app->bind(FactureServiceInterface::class, FactureService::class);
        $this->app->bind(PayoutServiceInterface::class, PayoutService::class);
    }

    /**
     * Boot the application events.
     */
    public function boot(Kernel $kernel): void
    {
        $this->loadMigrationsFrom(module_path('Paiements', 'Database/Migrations'));

        // Avant chaque requête API (de tous les modules) : le temps avance.
        // Via le Kernel : il resynchronise ses groupes vers le router.
        $kernel->prependMiddlewareToGroup('api', AvancerLeTemps::class);
    }
}
