<?php

namespace Modules\Core\App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Core\App\Services\NullTraceur;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(module_path('Core', 'config/config.php'), 'core');

        // Bind Services (le module Atelier fournit le vrai traceur)
        $this->app->bindIf(TraceurInterface::class, NullTraceur::class);
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        //
    }
}
