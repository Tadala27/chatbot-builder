<?php

// app/Services/TenantResolver.php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantResolver
{
    private string $landlordConnection;

    public function __construct()
    {
        $this->landlordConnection = config('tenancy.landlord_connection', 'landlord');
    }

    /**
     * Switch to the tenant's database for the rest of this request / operation.
     * After this call, DB::getDefaultConnection() returns the tenant connection name.
     */
    public function resolve(Tenant $tenant): string
    {
        $connectionName = 'tenant_'.$tenant->slug;

        if (!config("database.connections.{$connectionName}")) {
            $base = config('database.connections.mysql');

            // If the tenant has custom db_config (dedicated / existing DB), use it
            $dbConfig = $tenant->db_config ?? [];

            config(["database.connections.{$connectionName}" => array_merge($base, [
                'host' => $dbConfig['host'] ?? $base['host'],
                'port' => $dbConfig['port'] ?? $base['port'],
                'database' => $dbConfig['database'] ?? $tenant->db_schema,
                'username' => $dbConfig['username'] ?? $base['username'],
                'password' => $dbConfig['password'] ?? $base['password'],
                'prefix' => '',
            ])]);
        }

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);

        return $connectionName;
    }

    /**
     * Reset back to the landlord connection.
     * Always safe to call — disconnects any tenant connection first.
     */
    public function disconnect(Tenant $tenant): void
    {
        $tenantConnection = $this->tenantConnectionName($tenant);

        DB::disconnect($tenantConnection);
        DB::setDefaultConnection($this->landlordConnection);
    }

    private function resolveShared(Tenant $tenant): void
    {
        $connectionName = $this->tenantConnectionName($tenant);
        $schema = $tenant->db_schema;

        // Build the tenant connection config by copying the landlord config
        // and overriding only the database name. This inherits charset,
        // collation, host, port, credentials automatically.
        $landlordConfig = config("database.connections.{$this->landlordConnection}");

        if (empty($landlordConfig)) {
            throw new \RuntimeException("Landlord connection [{$this->landlordConnection}] not found in config/database.php.");
        }

        Config::set("database.connections.{$connectionName}", array_merge($landlordConfig, [
            'database' => $schema,
        ]));

        DB::setDefaultConnection($connectionName);

        // Verify the connection is usable. For a new tenant that has just had
        // its database created, this should always succeed.
        try {
            DB::connection($connectionName)->getPdo();
        } catch (\Exception $e) {
            DB::setDefaultConnection($this->landlordConnection);
            throw new \RuntimeException("Could not connect to tenant database [{$schema}]: ".$e->getMessage());
        }
    }

    /**
     * Dedicated mode: tenant has their own database, possibly on a different server.
     * Credentials are stored (encrypted) in tenants.db_connection JSON.
     */
    private function resolveDedicated(Tenant $tenant): void
    {
        $cfg = $tenant->db_connection;

        if (empty($cfg)) {
            throw new \RuntimeException("Tenant [{$tenant->id}] is in 'dedicated' mode but has no db_connection config stored.");
        }

        $connectionName = $this->tenantConnectionName($tenant);
        $landlordConfig = config("database.connections.{$this->landlordConnection}");

        Config::set("database.connections.{$connectionName}", array_merge($landlordConfig, [
            'driver' => $cfg['driver'] ?? $landlordConfig['driver'],
            'host' => $cfg['host'] ?? $landlordConfig['host'],
            'port' => $cfg['port'] ?? $landlordConfig['port'],
            'database' => $cfg['database'],
            'username' => $cfg['username'],
            'password' => $cfg['password'],
        ]));

        DB::setDefaultConnection($connectionName);

        try {
            DB::connection($connectionName)->getPdo();
        } catch (\Exception $e) {
            DB::setDefaultConnection($this->landlordConnection);
            throw new \RuntimeException("Could not connect to dedicated database for tenant [{$tenant->id}]: ".$e->getMessage());
        }
    }

    /**
     * Self-hosted mode: the app is running on the tenant's own server.
     * The .env already points at their DB — nothing to switch.
     */
    private function resolveSelfHosted(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            throw new \RuntimeException('Self-hosted DB connection failed: '.$e->getMessage());
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Consistent naming for runtime tenant connections.
     * e.g. tenant with db_schema='tenant_sunrise_mw' → 'tenant_sunrise_mw'
     * Using the schema name (not the tenant ID) makes it human-readable in
     * logs and Artisan output.
     */
    private function tenantConnectionName(Tenant $tenant): string
    {
        return $tenant->db_schema;
    }
}
