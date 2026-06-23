<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TenantStorageManager
{
    /**
     * Get a filesystem disk scoped to the current tenant's own storage.
     * Call this anywhere you need to store/retrieve tenant documents.
     *
     * Usage:
     *   $disk = TenantStorageManager::disk();
     *   $disk->put("documents/{$fileName}", $contents);
     *   $url  = $disk->temporaryUrl("documents/{$fileName}", now()->addHours(1));
     */
    public static function disk(?Tenant $tenant = null): FilesystemAdapter
    {
        $tenant ??= tenant();

        if (!$tenant) {
            throw new \RuntimeException('No tenant context available for storage resolution.');
        }

        $config = $tenant->storage_config;

        if (!$config || !isset($config['driver'])) {
            // Fallback: scoped local disk under storage/app/tenants/{tenant_id}/
            // Only suitable for development — not for production multi-tenant use
            return Storage::build([
                'driver' => 'local',
                'root' => storage_path("app/tenants/{$tenant->id}"),
            ]);
        }

        $diskName = "tenant_{$tenant->id}";

        // Register the disk dynamically if not already registered
        if (!config("filesystems.disks.{$diskName}")) {
            $diskConfig = match ($config['driver']) {
                's3' => [
                    'driver' => 's3',
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                    'region' => $config['region'],
                    'bucket' => $config['bucket'],
                    'url' => $config['url'] ?? null,
                    'endpoint' => $config['endpoint'] ?? null,
                    'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? false,
                    'visibility' => 'private',
                ],
                'sftp' => [
                    'driver' => 'sftp',
                    'host' => $config['host'],
                    'username' => $config['username'],
                    'password' => $config['password'] ?? null,
                    'privateKey' => $config['private_key'] ?? null,
                    'root' => $config['root'] ?? '/uploads',
                    'port' => $config['port'] ?? 22,
                ],
                default => [
                    'driver' => 'local',
                    'root' => storage_path("app/tenants/{$tenant->id}"),
                ],
            };

            config(["filesystems.disks.{$diskName}" => $diskConfig]);
        }

        return Storage::disk($diskName);
    }

    /**
     * Store an uploaded file in the tenant's own storage.
     * Returns the stored path.
     */
    public static function store(
        UploadedFile $file,
        string $directory = 'documents',
        ?Tenant $tenant = null,
    ): string {
        $disk = self::disk($tenant);

        return $disk->putFile($directory, $file);
    }

    /**
     * Generate a temporary URL for a tenant file (works with S3 and compatible drivers).
     * For SFTP/local, returns a signed local URL instead.
     */
    public static function temporaryUrl(string $path, int $minutes = 60, ?Tenant $tenant = null): string
    {
        $disk = self::disk($tenant);
        $config = ($tenant ?? tenant())?->storage_config ?? [];

        if (($config['driver'] ?? 'local') === 's3') {
            return $disk->temporaryUrl($path, now()->addMinutes($minutes));
        }

        // For non-S3: return a signed route that serves the file through Laravel
        return route('tenant.document.serve', [
            'path' => base64_encode($path),
            'signature' => hash_hmac('sha256', $path, config('app.key')),
            'expires' => now()->addMinutes($minutes)->timestamp,
        ]);
    }
}
