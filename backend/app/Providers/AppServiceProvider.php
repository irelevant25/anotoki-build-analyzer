<?php

namespace App\Providers;

use App\Services\Catalog\Catalog;
use App\Services\Http\Fetcher;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One fetcher per process so per-host throttling spans all requests.
        $this->app->singleton(Fetcher::class);
        // The catalog caches rows in memory; "scoped" resets it between queued jobs.
        $this->app->scoped(Catalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Windows PHP builds usually ship without a CA bundle (curl.cainfo is empty), which breaks HTTPS.
        // composer/ca-bundle finds the system bundle or falls back to its own copy of Mozilla's roots.
        Http::globalOptions(['verify' => CaBundle::getSystemCaRootBundlePath()]);
    }
}
