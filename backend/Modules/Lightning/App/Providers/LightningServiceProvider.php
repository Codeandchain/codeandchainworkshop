<?php

namespace Modules\Lightning\App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Lightning\App\Services\Bolt11Codec;
use Modules\Lightning\App\Services\Interfaces\Bolt11CodecInterface;

class LightningServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Bind Services
        $this->app->singleton(Bolt11CodecInterface::class, Bolt11Codec::class);
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        //
    }
}
