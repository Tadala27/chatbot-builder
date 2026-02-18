<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Tenant;

class TenantStatefulDomainsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only run this in console or web context, not during migrations
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            return;
        }

        try {
            // Get all tenant domains
            $domains = Tenant::whereNotNull('domain')->pluck('domain')->toArray();

            // Add port to domains if needed (for local development)
            $domainsWithPort = array_map(function ($domain) {
                return $domain . ':8000';
            }, $domains);

            // Merge with existing stateful domains
            $existingDomains = config('sanctum.stateful', []);
            $allDomains = array_unique(array_merge($existingDomains, $domains, $domainsWithPort));

            // Update config
            config(['sanctum.stateful' => $allDomains]);
        } catch (\Exception $e) {
            // Table might not exist yet (during migration)
            // Silently fail
        }
    }
}