<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Observers\TenantObserver;
use App\Services\Tenant\TenantDatabaseManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DemoTenantSeeder extends Seeder
{
    public function __construct(private TenantDatabaseManager $manager) {}

    public function run(): void
    {
        $this->command->info('Creating demo tenant...');

        TenantObserver::$enabled = false;

        $tenant = Tenant::firstOrCreate(
            ['id' => 'demo'],
            [
                'slug'                        => 'demo',
                'is_active'                   => true,
                'db_schema'                   => 'tenant_demo',
                'deployment_mode'             => 'shared',
                'subscription_tier'           => 'professional',
                'subscription_expires_at'     => now()->addYear(),
                'max_flows'                   => 10,
                'max_conversations_per_month' => 5000,
                'name'                        => 'Demo Organisation',
                'settings'                    => [
                    'timezone' => 'Africa/Blantyre',
                    'currency' => 'MWK',
                ],
            ]
        );

        $tenant->domains()->firstOrCreate(
            ['domain' => 'demo.localhost'],
            ['is_primary' => true]
        );

        $this->command->info("Tenant [{$tenant->id}] row created.");

        try {
            // Provision: create DB + run migrations + seed roles/permissions
            $this->manager->provision($tenant);
            $this->command->info("Tenant [{$tenant->id}] provisioned successfully.");

            // Seed demo-specific users and variables
            $this->manager->seedDemo($tenant);
            $this->command->info("Tenant [{$tenant->id}] demo data seeded.");
        } catch (\Throwable $e) {
            $this->command->error("Demo tenant setup failed: {$e->getMessage()}");
            Log::error($e);
        } finally {
            TenantObserver::$enabled = true;
        }
    }
}