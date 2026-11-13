<?php

namespace App\Providers;

use App\Services\ServiceCatalogue;
use Illuminate\Support\ServiceProvider;

class ServiceCatalogueProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request, so a page with several lists reads the setting once.
        $this->app->scoped(ServiceCatalogue::class);
    }
}
