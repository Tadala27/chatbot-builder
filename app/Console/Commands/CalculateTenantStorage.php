<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\StorageService;
use Illuminate\Console\Command;

class CalculateTenantStorage extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tenants:calculate-storage 
                            {tenant_id? : The ID of a specific tenant}
                            {--all : Calculate for all tenants}';

    /**
     * The console command description.
     */
    protected $description = 'Calculate and update storage usage for tenants';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(StorageService $storageService)
    {
        if ($this->option('all')) {
            return $this->calculateAllTenants($storageService);
        }

        if ($tenantId = $this->argument('tenant_id')) {
            return $this->calculateSingleTenant($tenantId, $storageService);
        }

        $this->error('Please specify either a tenant ID or use --all flag');
        return 1;
    }

    private function calculateAllTenants(StorageService $storageService)
    {
        $this->info('Calculating storage for all tenants...');

        $tenants = Tenant::all();
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        foreach ($tenants as $tenant) {
            $used = $storageService->calculateTenantStorage($tenant->id);
            $tenant->update(['storage_used' => $used]);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✓ Storage calculated for all tenants');

        // Show summary
        $stats = $storageService->getSystemStorageStats();
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Tenant', 'Used', 'Limit', 'Percentage'],
            collect($stats['tenants'])->map(fn($t) => [
                $t['name'],
                $storageService->formatBytes($t['storage_used']),
                $storageService->formatBytes($t['storage_limit']),
                number_format($t['percentage'], 2) . '%'
            ])
        );

        $this->info('Total System Storage: ' . $storageService->formatBytes($stats['total_storage_used']));

        return 0;
    }

    private function calculateSingleTenant(int $tenantId, StorageService $storageService)
    {
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant with ID {$tenantId} not found");
            return 1;
        }

        $this->info("Calculating storage for {$tenant->name}...");

        $used = $storageService->calculateTenantStorage($tenant->id);
        $tenant->update(['storage_used' => $used]);

        $percentage = $tenant->storage_limit > 0
            ? round(($used / $tenant->storage_limit) * 100, 2)
            : 0;

        $this->info('✓ Storage calculated successfully');
        $this->newLine();
        $this->info('Results:');
        $this->line('  Tenant: ' . $tenant->name);
        $this->line('  Used: ' . $storageService->formatBytes($used));
        $this->line('  Limit: ' . $storageService->formatBytes($tenant->storage_limit));
        $this->line('  Percentage: ' . $percentage . '%');

        if ($percentage >= 90) {
            $this->warn('⚠ WARNING: This tenant is using over 90% of their storage limit!');
        }

        return 0;
    }
}
