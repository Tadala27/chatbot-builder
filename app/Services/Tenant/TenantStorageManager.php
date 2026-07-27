<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TenantStorageManager
{
    /**
     * Normalise any path to forward slashes.
     * Prevents Windows DIRECTORY_SEPARATOR (\) leaking into S3 keys.
     */
    private static function p(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Return the tenant-scoped prefix for all keys in the shared bucket.
     * This is prepended manually to every path — we do NOT use Flysystem's
     * 'root' config key because on Windows it joins root+path with backslash.
     *
     * Full S3 key = tenants/{tenant_id}/{relative_path}
     */
    private static function tenantPrefix(Tenant $tenant): string
    {
        return 'tenants/'.$tenant->id;
    }

    /**
     * Prepend the tenant prefix to a relative path, always with forward slashes.
     */
    private static function tenantPath(string $relativePath, Tenant $tenant): string
    {
        return self::p(self::tenantPrefix($tenant).'/'.ltrim($relativePath, '/\\'));
    }

    /**
     * Get the base S3 disk (no root prefix — we handle prefixing ourselves).
     * Falls back to a local disk per tenant if AWS is not configured.
     */
    public static function disk(?Tenant $tenant = null): FilesystemAdapter
    {
        $tenant ??= tenant();

        if (!$tenant) {
            throw new \RuntimeException('No tenant context — cannot resolve storage disk.');
        }

        $config = $tenant->storage_config ?? [];

        // Tenant has their own custom storage
        if (!empty($config['driver']) && !in_array($config['driver'], ['system', 'local'], true)) {
            return self::buildCustomDisk($config, $tenant);
        }

        return self::buildSystemDisk($tenant);
    }

    private static function buildSystemDisk(Tenant $tenant): FilesystemAdapter
    {
        $awsConfigured = !empty(config('filesystems.disks.s3.key'))
                      && !empty(config('filesystems.disks.s3.bucket'));

        if (!$awsConfigured) {
            Log::debug('[TenantStorage] AWS not configured — using local disk', ['tenant' => $tenant->id]);

            return Storage::build([
                'driver' => 'local',
                'root' => storage_path('app/tenants/'.$tenant->id),
            ]);
        }

        // Use the base s3 disk with NO root prefix.
        // We prepend the tenant prefix in every method call ourselves,
        // so Flysystem never has a chance to join paths with a backslash.
        return Storage::disk('s3');
    }

    private static function buildCustomDisk(array $config, Tenant $tenant): FilesystemAdapter
    {
        $diskName = 'tenant_custom_'.$tenant->id;

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
                    'port' => (int) ($config['port'] ?? 22),
                ],
                default => [
                    'driver' => 'local',
                    'root' => storage_path('app/tenants/'.$tenant->id),
                ],
            };
            config(["filesystems.disks.{$diskName}" => $diskConfig]);
        }

        return Storage::disk($diskName);
    }

    // =========================================================================
    // PUBLIC API — every method prepends the tenant prefix itself
    // =========================================================================

    /**
     * Store an UploadedFile under the tenant prefix.
     * Returns the relative path (without tenant prefix) for storage in the DB.
     *
     * Full S3 key: tenants/{tenant_id}/{directory}/{filename}
     * Stored in DB: {directory}/{filename}   ← relative, prefix-free
     */
    public static function store(
        UploadedFile $file,
        string $directory = 'media',
        ?string $filename = null,
        ?Tenant $tenant = null,
    ): string {
        $t = $tenant ?? tenant();
        $filename = self::p($filename ?? Str::uuid()->toString().'.'.$file->getClientOriginalExtension());
        $directory = self::p($directory);
        $relative = "{$directory}/{$filename}";
        $fullPath = self::tenantPath($relative, $t);

        self::disk($t)->putFileAs(
            dirname($fullPath),
            $file,
            basename($fullPath),
        );

        return $relative;
    }

    /**
     * Store raw binary content under the tenant prefix.
     * $path is relative (no tenant prefix) — same as what you store in the DB.
     */
    public static function putContent(string $path, string $contents, ?Tenant $tenant = null): bool
    {
        $t = $tenant ?? tenant();

        return self::disk($t)->put(self::tenantPath($path, $t), $contents);
    }

    /**
     * Generate a temporary URL.
     * $path is relative — tenant prefix is added here before signing.
     * The resulting URL will always have forward slashes in the S3 key.
     */
    public static function temporaryUrl(string $path, int $minutes = 60, ?Tenant $tenant = null): string
    {
        $t = $tenant ?? tenant();
        $config = $t?->storage_config ?? [];
        $driver = $config['driver'] ?? 'system';
        $fullPath = self::tenantPath($path, $t);

        $isS3 = $driver === 's3'
            || ($driver === 'system' && !empty(config('filesystems.disks.s3.key')));

        if ($isS3) {
            try {
                return self::disk($t)->temporaryUrl($fullPath, now()->addMinutes($minutes));
            } catch (\Exception $e) {
                Log::warning('[TenantStorage] S3 temporaryUrl failed, falling back to proxy', [
                    'tenant' => $t?->id,
                    'path' => $fullPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return URL::temporarySignedRoute(
            'tenant.document.serve',
            now()->addMinutes($minutes),
            ['path' => base64_encode($path), 'tenant_id' => $t?->id],
        );
    }

    /**
     * The full S3 key as visible in the AWS console.
     * tenants/{tenant_id}/{relative_path}.
     */
    public static function fullKey(string $path, ?Tenant $tenant = null): string
    {
        $t = $tenant ?? tenant();
        $config = $t?->storage_config ?? [];
        $driver = $config['driver'] ?? 'system';

        if (!in_array($driver, ['system', 'local'], true)) {
            return self::p(ltrim($path, '/'));
        }

        return self::tenantPath($path, $t);
    }

    public static function exists(string $path, ?Tenant $tenant = null): bool
    {
        $t = $tenant ?? tenant();

        return self::disk($t)->exists(self::tenantPath($path, $t));
    }

    public static function get(string $path, ?Tenant $tenant = null): ?string
    {
        $t = $tenant ?? tenant();
        $fullPath = self::tenantPath($path, $t);
        $disk = self::disk($t);

        return $disk->exists($fullPath) ? $disk->get($fullPath) : null;
    }

    public static function delete(string $path, ?Tenant $tenant = null): bool
    {
        $t = $tenant ?? tenant();

        return self::disk($t)->delete(self::tenantPath($path, $t));
    }

    public static function mimeType(string $path, ?Tenant $tenant = null): string
    {
        $t = $tenant ?? tenant();

        return self::disk($t)->mimeType(self::tenantPath($path, $t)) ?? 'application/octet-stream';
    }

    public static function size(string $path, ?Tenant $tenant = null): int
    {
        $t = $tenant ?? tenant();

        return self::disk($t)->size(self::tenantPath($path, $t));
    }

    public static function validateConfig(array $config): array
    {
        $errors = [];
        $driver = $config['driver'] ?? 'system';

        if ($driver === 's3') {
            foreach (['key', 'secret', 'region', 'bucket'] as $f) {
                if (empty($config[$f])) {
                    $errors[] = "S3: '{$f}' is required.";
                }
            }
        }
        if ($driver === 'sftp') {
            foreach (['host', 'username'] as $f) {
                if (empty($config[$f])) {
                    $errors[] = "SFTP: '{$f}' is required.";
                }
            }
            if (empty($config['password']) && empty($config['private_key'])) {
                $errors[] = "SFTP: either 'password' or 'private_key' is required.";
            }
        }

        return $errors;
    }
}