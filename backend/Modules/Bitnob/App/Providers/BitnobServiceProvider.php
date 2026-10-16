<?php

namespace Modules\Bitnob\App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Bitnob\App\Repositories\Eloquent\EloquentFactureSimuleeRepository;
use Modules\Bitnob\App\Repositories\Interfaces\FactureSimuleeRepositoryInterface;
use Modules\Bitnob\App\Services\HttpBitnobClient;
use Modules\Bitnob\App\Services\Interfaces\BitnobClientInterface;
use Modules\Bitnob\App\Services\Interfaces\SignatureWebhookInterface;
use Modules\Bitnob\App\Services\SignatureWebhook;
use Modules\Bitnob\App\Services\SimulationBitnobClient;

class BitnobServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(module_path('Bitnob', 'config/config.php'), 'bitnob');

        // Bind Repositories
        $this->app->bind(FactureSimuleeRepositoryInterface::class, EloquentFactureSimuleeRepository::class);

        // Bind Services
        $this->app->bind(SignatureWebhookInterface::class, SignatureWebhook::class);

        // Vrai Bitnob ou faux Bitnob : MOCK_PAYMENTS décide, au moment
        // où le client est demandé (les tests peuvent changer la config).
        $this->app->bind(BitnobClientInterface::class, fn ($app) => config('bitnob.mock')
            ? $app->make(SimulationBitnobClient::class)
            : $app->make(HttpBitnobClient::class));
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Bitnob', 'Database/Migrations'));
    }
}
