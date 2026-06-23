<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Services\Tenant\TenantDatabaseManager;
use Illuminate\Support\Facades\Log;

class TenantObserver
{
    public function __construct(private TenantDatabaseManager $manager)
    {
    }
    public static bool $enabled = true;

    /**
     * After a tenant row is created, set up their database.
     * This runs synchronously — for large deployments you may want to
     * dispatch a queued job here instead.
     */
    public function created(Tenant $tenant): void
    {
        if (!self::$enabled) {
            return;
        }
        try {
            $this->manager->provision($tenant);

            Log::info("Tenant [{$tenant->id}] {$tenant->slug} provisioned successfully.", [
                'mode' => $tenant->deployment_mode,
                'schema' => $tenant->db_schema,
            ]);
        } catch (\Throwable $e) {
            // Log but don't re-throw — the tenant row exists; provisioning
            // can be retried via the admin panel.
            Log::error("Tenant [{$tenant->id}] provisioning failed: ".$e->getMessage(), [
                'tenant_id' => $tenant->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * When a tenant is force-deleted (hard delete), remove their schema.
     * Soft deletes do NOT trigger this — the schema stays until permanent deletion.
     */
    public function forceDeleted(Tenant $tenant): void
    {
        try {
            $this->manager->destroy($tenant);
        } catch (\Throwable $e) {
            Log::error("Tenant [{$tenant->id}] schema destruction failed: ".$e->getMessage());
        }
    }
}