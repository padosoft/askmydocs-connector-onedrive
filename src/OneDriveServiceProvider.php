<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorOneDrive;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Microsoft OneDrive connector package.
 *
 * Merges the OneDrive provider block into the host's `connectors.php`
 * config tree (under `providers.onedrive`). Publishes both the config
 * fragment + the brand asset for hosts that want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class OneDriveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/onedrive.php', 'connectors.providers.onedrive');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/onedrive.php' => config_path('connectors-onedrive.php'),
            ], 'connector-onedrive-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-onedrive-assets');
        }
    }
}
