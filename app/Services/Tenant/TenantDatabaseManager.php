<?php

// app/Services/TenantDatabaseManager.php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TenantDatabaseManager
{
    private string $landlordConnection;

    public function __construct(private TenantResolver $resolver)
    {
        $this->landlordConnection = config('tenancy.landlord_connection', 'landlord');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════════════════

    public function provision(Tenant $tenant): void
    {
        Log::info("Tenant [{$tenant->slug}] — starting provisioning (mode: {$tenant->deployment_mode}).");

        try {
            $this->createStorage($tenant);
            $this->runMigrations($tenant);
            $this->seedDefaultData($tenant);

            Log::info("Tenant [{$tenant->slug}] — provisioning complete.");
        } catch (\Throwable $e) {
            Log::error("Tenant [{$tenant->slug}] — provisioning failed: {$e->getMessage()}");
            throw $e;
        } finally {
            $this->resetToLandlord();
        }
    }

    /**
     * Seed demo-specific users and variables into an already-provisioned
     * tenant database. Handles its own connection switching — the caller
     * does not need to call tenancy()->initialize() / tenancy()->end().
     */
    public function seedDemo(Tenant $tenant): void
    {
        Log::info("Tenant [{$tenant->slug}] — seeding demo data.");

        $this->resolver->resolve($tenant);

        try {
            $this->seedDemoUsers();
            $this->seedDemoAccount();
            $this->seedDemoVariables();

            Log::info("Tenant [{$tenant->slug}] — demo data seeded.");
        } catch (\Throwable $e) {
            Log::error("Tenant [{$tenant->slug}] — demo seeding failed: {$e->getMessage()}");
            throw $e;
        } finally {
            $this->resetToLandlord();
        }
    }

    /**
     * Run migrations (and seed) on a tenant that already has a database
     * but may or may not have tables yet.
     *
     * Safe to call on an already-migrated tenant — migrations are idempotent
     * and seeding uses firstOrCreate.
     */
    public function runMigrationsOnly(Tenant $tenant): void
    {
        Log::info("Tenant [{$tenant->slug}] — running migrations.");

        try {
            $connection = $this->resolver->resolve($tenant);

            if (!$this->hasMigrationsTable($connection)) {
                Log::info("Tenant [{$tenant->slug}] — no migrations table found; running fresh migration.");
            }

            $this->executeMigrate($connection, $tenant->slug);
            $this->seedDefaultData($tenant);

            Log::info("Tenant [{$tenant->slug}] — migrations and seeding complete.");
        } catch (\Throwable $e) {
            Log::error("Tenant [{$tenant->slug}] — migration failed: {$e->getMessage()}");
            throw $e;
        } finally {
            $this->resetToLandlord();
        }
    }

    /**
     * Drop the tenant's schema/database.
     * Only supported for shared-mode tenants; other modes log a warning
     * and leave manual cleanup to the operator.
     */
    public function destroy(Tenant $tenant): void
    {
        if ($tenant->deployment_mode !== 'shared') {
            Log::warning(
                "Tenant [{$tenant->id}] is in [{$tenant->deployment_mode}] mode — ".
                'database not dropped automatically. Remove it manually.'
            );

            return;
        }

        Log::info("Tenant [{$tenant->slug}] — dropping schema [{$tenant->db_schema}].");

        try {
            $schema = $tenant->db_schema;
            $driver = $this->getLandlordDriver();
            $conn = $this->landlordConnection;

            match ($driver) {
                'pgsql' => DB::connection($conn)->statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE"),
                'mysql' => DB::connection($conn)->statement("DROP DATABASE IF EXISTS `{$schema}`"),
                default => throw new \RuntimeException("Schema deletion not supported for driver [{$driver}]."),
            };

            Log::info("Tenant [{$tenant->slug}] — schema [{$schema}] dropped.");
        } finally {
            $this->resetToLandlord();
        }
    }

    /**
     * Switch the default DB connection back to the landlord and
     * clean up any lingering tenant connection.
     */
    public function resetToLandlord(): void
    {
        $current = DB::getDefaultConnection();

        if ($current !== $this->landlordConnection) {
            DB::disconnect($current);
        }

        DB::disconnect($this->landlordConnection);
        DB::setDefaultConnection($this->landlordConnection);
        DB::reconnect($this->landlordConnection);

        if ($this->getLandlordDriver() === 'pgsql') {
            DB::connection($this->landlordConnection)
              ->statement('SET search_path TO public');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Storage provisioning
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Dispatch to the correct storage setup based on deployment mode.
     */
    private function createStorage(Tenant $tenant): void
    {
        match ($tenant->deployment_mode) {
            'shared' => $this->createSharedSchema($tenant),
            'dedicated' => $this->verifyDedicatedConnection($tenant),
            'self_hosted' => $this->verifySelfHostedConnection(),
            default => throw new \RuntimeException("Unknown deployment mode [{$tenant->deployment_mode}] for tenant [{$tenant->slug}]."),
        };
    }

    /**
     * Shared mode: create a MySQL database or Postgres schema for the tenant.
     */
    private function createSharedSchema(Tenant $tenant): void
    {
        $schema = $tenant->db_schema;
        $driver = $this->getLandlordDriver();
        $conn = $this->landlordConnection;

        Log::info("Tenant [{$tenant->slug}] — creating shared schema [{$schema}] on [{$driver}].");

        match ($driver) {
            'pgsql' => DB::connection($conn)
                          ->statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\""),
            'mysql' => DB::connection($conn)
                          ->statement(
                              "CREATE DATABASE IF NOT EXISTS `{$schema}` ".
                              'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                          ),
            default => throw new \RuntimeException("Shared provisioning not supported for driver [{$driver}]. Landlord connection is [{$conn}]."),
        };

        // Flush the landlord connection so it picks up the new schema.
        DB::disconnect($conn);
        DB::reconnect($conn);
    }

    /**
     * Dedicated mode: the tenant's DB already exists on a separate server.
     * Just resolve the connection and confirm we can reach it.
     */
    private function verifyDedicatedConnection(Tenant $tenant): void
    {
        $this->resolver->resolve($tenant);
        $this->resetToLandlord();
    }

    /**
     * Self-hosted mode: the .env already points at the tenant's DB.
     * Just assert the connection is alive.
     */
    private function verifySelfHostedConnection(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            throw new \RuntimeException("Self-hosted DB connection failed: {$e->getMessage()}");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Migrations
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Resolve the tenant connection, run Artisan migrate, then reset.
     *
     * Pre-flight: if the migrations table is absent we log it but still
     * proceed — `artisan migrate` will create it and run everything fresh.
     */
    private function runMigrations(Tenant $tenant): void
    {
        $connection = $this->resolver->resolve($tenant);

        // Get all tables currently in the tenant DB
        $tables = $this->getTenantTables($connection);

        if (empty($tables)) {
            // Fresh DB — nothing there yet, proceed normally
            Log::info("Tenant [{$tenant->slug}] — empty database, running fresh migrations.");
        } elseif ($tables === ['migrations']) {
            // Only the migrations tracking table exists with no recorded rows —
            // this is a leftover from a previous failed provisioning attempt.
            // Drop it so Laravel starts completely fresh.
            Log::warning(
                "Tenant [{$tenant->slug}] — stale empty migrations table detected. ".
                'Dropping it and re-running all migrations.'
            );
            DB::connection($connection)->statement('DROP TABLE `migrations`');
        } else {
            // Tables exist — migrations have run before, proceed normally (idempotent)
            Log::info(
                "Tenant [{$tenant->slug}] — ".count($tables).' table(s) found, '.
                'running incremental migrations.'
            );
        }

        try {
            $this->executeMigrate($connection, $tenant->slug);
            $this->assertRequiredTablesExist($connection, $tenant->slug);
        } finally {
            $this->resetToLandlord();
        }
    }

    /**
     * Return a flat list of table names on the given connection.
     */
    private function getTenantTables(string $connection): array
    {
        try {
            $tables = DB::connection($connection)->getSchemaBuilder()->getTables();

            return array_map(
                fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t,
                $tables
            );
        } catch (\Throwable $e) {
            Log::warning("Could not list tables on [{$connection}]: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Assert that Spatie's permission tables exist after migration.
     * A clear error here is far better than a cryptic SQL error during seeding.
     */
    private function assertRequiredTablesExist(string $connection, string $tenantSlug): void
    {
        $required = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];
        $schema = DB::connection($connection)->getSchemaBuilder();
        $missing = array_filter($required, fn ($t) => !$schema->hasTable($t));

        if (!empty($missing)) {
            throw new \RuntimeException("Tenant [{$tenantSlug}] — migrations ran but required tables are still missing: ".implode(', ', $missing).'. Verify that database/migrations/tenant/ contains the Spatie permission migration and that base_path() resolves correctly.');
        }
    }

    /**
     * Thin wrapper around `artisan migrate` so the options are defined
     * in one place and shared between runMigrations() and runMigrationsOnly().
     */
    private function executeMigrate(string $connection, string $tenantSlug): void
    {
        $path = base_path('database/migrations/tenant');

        if (!is_dir($path)) {
            throw new \RuntimeException("Tenant migration path does not exist: [{$path}]. ".'Create the directory and add your tenant migration files.');
        }

        $files = glob("{$path}/*.php");
        Log::info("Tenant [{$tenantSlug}] — found ".count($files)." migration file(s) in [{$path}].");

        Artisan::call('migrate', [
            '--path' => $path,        // absolute path
            '--realpath' => true,
            '--force' => true,
            '--database' => $connection,
        ]);

        $output = Artisan::output();
        Log::info("Tenant [{$tenantSlug}] — migration output: {$output}");

        if (app()->runningInConsole()) {
            echo $output;
        }
    }

    /**
     * Check whether the tenant DB has been migrated at all.
     *
     * We look for the `migrations` table — Laravel creates it on the
     * very first `artisan migrate` run. Its absence means the DB exists
     * but has never had migrations applied.
     *
     * Returns false (and logs a warning) on any connection error so that
     * the caller treats the situation as "needs migration" rather than
     * silently skipping it.
     */
    private function hasMigrationsTable(string $connection): bool
    {
        try {
            $tables = DB::connection($connection)->getSchemaBuilder()->getTables();

            if (empty($tables)) {
                return false;
            }

            // Laravel 11+ returns arrays per table; Laravel 10 returns strings.
            $names = array_map(
                fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t,
                $tables
            );

            return in_array('migrations', $names, true);
        } catch (\Throwable $e) {
            Log::warning(
                "Could not inspect tables on connection [{$connection}]: {$e->getMessage()}. ".
                'Treating as unmigrated.'
            );

            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Seeding
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Seed permissions and roles into the tenant's own database.
     *
     * - Runs on the tenant connection via Spatie's ::on() scoping.
     * - All inserts use firstOrCreate — safe to call repeatedly.
     * - Clears Spatie's permission cache before and after so no tenant
     *   bleeds into the next request.
     */
    private function seedDefaultData(Tenant $tenant): void
    {
        $this->resolver->resolve($tenant);
        $connection = DB::getDefaultConnection();

        try {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            $this->createPermissions($connection);
            $this->createRoles($connection);

            Log::info("Tenant [{$tenant->slug}] — permissions and roles seeded.");
        } catch (\Throwable $e) {
            Log::error("Tenant [{$tenant->slug}] — seeding failed: {$e->getMessage()}");
            throw $e;
        } finally {
            $this->resetToLandlord();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    private function createPermissions(string $connection): void
    {
        foreach ($this->permissionGroups() as $perms) {
            foreach ($perms as $perm) {
                Permission::on($connection)->firstOrCreate([
                    'name' => $perm,
                    'guard_name' => 'tenant',
                ]);
            }
        }
    }

    private function createRoles(string $connection): void
    {
        $allPermissions = Permission::on($connection)
            ->where('guard_name', 'tenant')
            ->get();

        foreach ($this->roleDefinitions() as $roleName => $rolePermissions) {
            $role = Role::on($connection)->firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'tenant',
            ]);

            if ($rolePermissions === '*') {
                $role->syncPermissions($allPermissions);
            } else {
                $perms = Permission::on($connection)
                    ->where('guard_name', 'tenant')
                    ->whereIn('name', $rolePermissions)
                    ->get();

                $role->syncPermissions($perms);
            }
        }
    }

    /**
     * Seed demo users. Called from seedDemo() which has already switched
     * the active connection to the tenant database.
     */
    private function seedDemoUsers(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Demo Admin',
                'password' => Hash::make('password@123'),
            ]
        );
        $admin->assignRole('tenant-admin');

        $agent = User::firstOrCreate(
            ['email' => 'agent@demo.com'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Demo Agent',
                'password' => Hash::make('password@123'),
            ]
        );
        $agent->assignRole('agent');

        $viewer = User::firstOrCreate(
            ['email' => 'viewer@demo.com'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Demo Viewer',
                'password' => Hash::make('password@123'),
            ]
        );
        $viewer->assignRole('viewer');
    }

    public function seedDemoAccount(): void
    {
        DB::table('whatsapp_accounts')->insert([
            'id' => Str::uuid()->toString(),

            // ── Identity ─────────────────────────────────────────────────────
            'waba_id' => '2124195388053485',
            'phone_number_id' => '632142966655683',
            'phone_number' => '+15556569855',
            'display_phone_number' => '+1 555 656 9855',
            'verified_name' => 'Test Number',

            // ── Onboarding ───────────────────────────────────────────────────
            'onboarding_method' => 'embedded_signup',
            'onboarding_status' => 'active',
            'verification_method' => 'sms',
            'mode' => 'managed_bot',

            // ── Credentials ──────────────────────────────────────────────────
            'access_token' => encrypt('EAARD7xiMKCkBPHIUpZCpkZBD2hdLYJnL8jZAR9DZB15OZCrKNcP6Q4CPu0ZCpNgmd19YicPcv2XVUwMqZB8ydJ8yIJYZBVYlAkJT9ovEYDmPwSlEfZAL7eMhmUp0IsmCwWrKxhKxdFGBGptZAstmMKnQZCKeQJvAdwFL5ZBDsinlGqcKgdtzAwyCVinvaHAldBIcOwZDZD'),
            'phone_number_pin' => '123456',
            'webhook_verify_token' => 'MySecretToken',

            // ── Quality ───────────────────────────────────────────────────────
            'quality_rating' => 'GREEN',
            'messaging_limit' => 'TIER_1K',

            // ── State ─────────────────────────────────────────────────────────
            'is_active' => true,
            'registered_at' => Carbon::now(),
            'last_synced_at' => Carbon::now(),

            // ── Metadata: health fields + business info ────────────────────────
            // name_status, phone_status, code_verification_status, platform_type,
            // throughput_level, account_review_status have no real columns in this
            // migration — they live here and are populated/updated by sync().
            'metadata' => json_encode([
                'name_status' => 'APPROVED',
                'phone_status' => 'CONNECTED',
                'code_verification_status' => 'VERIFIED',
                'platform_type' => 'CLOUD_API',
                'throughput_level' => 'STANDARD',
                'account_review_status' => 'APPROVED',
                'health_status' => ['can_send_message' => 'AVAILABLE'],
                'business_id' => '1029384756102938',
                'business_name' => 'Demo Business Ltd',
                'business_email' => 'demo@example.com',
                'currency' => 'USD',
                'timezone' => 'Africa/Blantyre',
            ]),

            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed demo variables. Called from seedDemo() which has already switched
     * the active connection to the tenant database.
     */
    private function seedDemoVariables(): void
    {
        // Requires a bot to exist — skip silently if none yet
        $botId = DB::table('bots')->value('id');

        if (!$botId) {
            Log::info('Demo variable seeding skipped — no bot found in tenant database.');

            return;
        }

        $variables = [
            // Identity & Authentication
            'npNationalId' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'National ID'],
            'npPassword' => ['data_type' => 'string', 'save_in' => 'user_property', 'is_sensitive' => true, 'description' => 'Password'],

            // Member Information
            'member' => ['data_type' => 'string', 'save_in' => 'conversation',   'description' => 'Member'],
            'member_Id' => ['data_type' => 'string', 'save_in' => 'user_property',  'description' => 'Member ID'],
            'MemberBeneficiaries' => ['data_type' => 'json',   'save_in' => 'user_property',  'description' => 'Member beneficiaries list'],
            'MemberClass' => ['data_type' => 'string', 'save_in' => 'user_property',  'description' => 'Member class/category'],
            'memberGender' => ['data_type' => 'string', 'save_in' => 'user_property',  'description' => 'Member gender'],
            'memberId' => ['data_type' => 'string', 'save_in' => 'user_property',  'description' => 'Member ID'],
            'memberName' => ['data_type' => 'string', 'save_in' => 'user_property',  'description' => 'Member name'],
            'memberNumber' => ['data_type' => 'string', 'save_in' => 'user_property',  'description' => 'Member number'],

            // Company
            'companyName' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Company name'],

            // Employment
            'DoJ' => ['data_type' => 'date',   'save_in' => 'user_property', 'description' => 'Date of Joining'],
            'DoE' => ['data_type' => 'date',   'save_in' => 'user_property', 'description' => 'Date of Exit'],
            'servicePeriod' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Service period'],

            // Schemes
            'numberofschemes' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Number of schemes'],

            // Multiple Members
            'memberId1' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 1'],
            'memberId2' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 2'],
            'memberId3' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 3'],
            'memberId4' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 4'],
            'memberName1' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 1'],
            'memberName2' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 2'],
            'memberName3' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 3'],
            'memberName4' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 4'],

            // Balances
            'eebalance' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Employee balance'],
            'erbalance' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Employer balance'],
            'accountBalance' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Total account balance'],
            'Contributionyear' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Contribution year'],
        ];

        // Add monthly contribution variables programmatically
        $months = [
            'january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december',
        ];

        foreach ($months as $month) {
            $label = ucfirst($month);
            $variables["{$month}employeecontribution"] = ['data_type' => 'number', 'save_in' => 'user_property', 'description' => "{$label} employee contribution"];
            $variables["{$month}employercontribution"] = ['data_type' => 'number', 'save_in' => 'user_property', 'description' => "{$label} employer contribution"];
            $variables["{$month}totalcontribution"] = ['data_type' => 'number', 'save_in' => 'user_property', 'description' => "{$label} total contribution"];
            $variables["{$month}datepaid"] = ['data_type' => 'date',   'save_in' => 'user_property', 'description' => "{$label} date paid"];
        }

        $now = now();

        foreach ($variables as $key => $config) {
            $name = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $key);
            $name = ucfirst(str_replace('_', ' ', $name));

            DB::table('custom_variables')->insertOrIgnore([
                'bot_id' => $botId,
                'name' => $name,
                'key' => $key,
                'data_type' => $config['data_type'],
                'default_value' => null,
                'save_in' => $config['save_in'],
                'use_in_js' => false,
                'is_sensitive' => $config['is_sensitive'] ?? false,
                'description' => $config['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Permission & role definitions
    // ══════════════════════════════════════════════════════════════════════

    private function permissionGroups(): array
    {
        return [
            'users' => [
                'view users',
                'invite users',
                'edit users',
                'delete users',
                'assign roles',
                'reset user password',
            ],
            'bots' => [
                'view bots',
                'create bots',
                'edit bots',
                'delete bots',
                'publish bots',
                'test bots',
                'duplicate bots',
            ],

            'variables' => [
                'view variables',
                'create variables',
                'edit variables',
                'delete variables',
            ],
            'functions' => [
                'view functions',
                'create functions',
                'edit functions',
                'delete functions',
                'execute functions',
                'test functions',
            ],
            'whatsapp' => [
                'view whatsapp-accounts',
                'connect whatsapp-accounts',
                'disconnect whatsapp-accounts',
                'manage whatsapp-accounts',
            ],
            'conversations' => [
                'view conversations',
                'view conversation-details',
                'export conversations',
                'delete conversations',
                'handoff conversations',
            ],
            'analytics' => [
                'view analytics',
                'view detailed-analytics',
                'export analytics',
            ],
            'integrations' => [
                'view integrations',
                'create integrations',
                'edit integrations',
                'delete integrations',
                'test integrations',
            ],
            'templates' => [
                'view templates',
                'create templates',
                'edit templates',
                'delete templates',
                'submit templates',
            ],
            'webhooks' => [
                'view webhooks',
                'create webhooks',
                'edit webhooks',
                'delete webhooks',
            ],
            'settings' => [
                'view settings',
                'manage settings',
                'manage billing',
            ],
        ];
    }

    // ── Role definitions ──────────────────────────────────────────────────────

    private function roleDefinitions(): array
    {
        return [
            // Full access within the tenant — cannot touch tenant subscription
            // (that's a central admin concern)
            'tenant-admin' => '*',

            'bot-builder' => [
                'view bots',
                'create bots',
                'edit bots',
                'test bots',
                'duplicate bots',
                'create nodes',
                'edit nodes',
                'delete nodes',
                'view variables',
                'create variables',
                'edit variables',
                'delete variables',
                'view functions',
                'execute functions',
                'test functions',
                'view conversations',
                'view conversation-details',
                'view analytics',
                'view templates',
                'view integrations',
            ],

            // Handles live conversations only
            'agent' => [
                'view conversations',
                'view conversation-details',
                'handoff conversations',
                'view bots',
            ],

            // Read-only access across the tenant
            'viewer' => [
                'view bots',
                'view conversations',
                'view analytics',
                'view templates',
                'view integrations',
                'view functions',
                'view variables',
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function getLandlordDriver(): string
    {
        $driver = config("database.connections.{$this->landlordConnection}.driver");

        if (empty($driver)) {
            throw new \RuntimeException("Could not determine driver for landlord connection [{$this->landlordConnection}]. ".'Check that this connection exists in config/database.php.');
        }

        return $driver;
    }
}
