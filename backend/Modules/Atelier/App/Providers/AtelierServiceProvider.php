<?php

namespace Modules\Atelier\App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Atelier\App\Listeners\MemoriserDernierWebhook;
use Modules\Atelier\App\Repositories\Eloquent\EloquentEchangeRepository;
use Modules\Atelier\App\Repositories\Eloquent\EloquentJournalRepository;
use Modules\Atelier\App\Repositories\Eloquent\EloquentReglageRepository;
use Modules\Atelier\App\Repositories\Interfaces\EchangeRepositoryInterface;
use Modules\Atelier\App\Repositories\Interfaces\JournalRepositoryInterface;
use Modules\Atelier\App\Repositories\Interfaces\ReglageRepositoryInterface;
use Modules\Atelier\App\Services\AtelierService;
use Modules\Atelier\App\Services\Interfaces\AtelierServiceInterface;
use Modules\Atelier\App\Services\TraceurService;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Paiements\App\Events\WebhookTraite;

class AtelierServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(module_path('Atelier', 'config/config.php'), 'atelier');
        $this->app->register(RouteServiceProvider::class);

        // Bind Repositories
        $this->app->bind(JournalRepositoryInterface::class, EloquentJournalRepository::class);
        $this->app->bind(EchangeRepositoryInterface::class, EloquentEchangeRepository::class);
        $this->app->bind(ReglageRepositoryInterface::class, EloquentReglageRepository::class);

        // Bind Services — remplace le NullTraceur du Core
        $this->app->bind(TraceurInterface::class, TraceurService::class);
        $this->app->bind(AtelierServiceInterface::class, AtelierService::class);
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Atelier', 'Database/Migrations'));

        Event::listen(WebhookTraite::class, MemoriserDernierWebhook::class);
    }
}
