<?php

namespace Webpatser\LaravelFiber;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\ServiceProvider;

class FiberServiceProvider extends ServiceProvider
{
    /**
     * Register the fiber concurrency driver.
     */
    public function boot(): void
    {
        $this->app->make(ConcurrencyManager::class)
            ->extend('fiber', fn ($app, $config) => new FiberDriver);
    }
}
