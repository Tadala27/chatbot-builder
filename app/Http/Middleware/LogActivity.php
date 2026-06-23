<?php

// app/Http/Middleware/LogActivity.php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\Response;

class LogActivity
{
    protected array $skipRoutes = [
        'tenant/auth/login',
        'tenant/auth/logout',
        'tenant/auth/change-password',
        'api/auth/login',
        'api/auth/logout',
        'tenant/employees/bulk-template',
        'tenant/documents/serve',
    ];

    protected array $mutateMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    // Fields never included in diffs
    protected array $skipFields = [
        'created_at', 'updated_at', 'deleted_at',
        'remember_token', 'email_verified_at', 'last_login',
        'data', 'tenancy_db_name', 'db_connection', 'db_config', 'storage_config',
    ];

    // Fields whose values are always redacted
    protected array $sensitiveFields = [
        'password', 'password_confirmation', 'current_password', 'account_number',
        'token', 'secret', 'api_key', 'access_token', 'refresh_token',
        'db_password', 'db_config', 'db_connection',
    ];

    public function handle(Request $request, \Closure $next): Response
    {
        if (!in_array($request->method(), $this->mutateMethods)) {
            return $next($request);
        }

        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $isSystemRoute = str_starts_with($request->path(), 'api/');
        $guard = $isSystemRoute ? 'system' : 'tenant';
        $user = Auth::guard($guard)->user();

        $subject = $this->resolveSubjectFromRoute($request);
        $originalData = ($subject && in_array($request->method(), ['PUT', 'PATCH']))
            ? $subject->getOriginal()
            : null;

        $payload = $this->sanitizePayload($request->all());
        $exception = null;
        $response = null;

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $statusCode = $exception ? 500 : $response->getStatusCode();
        $isSuccess = !$exception && $statusCode < 400;
        $outcome = $isSuccess ? 'success' : 'failure';

        $changes = [];
        if ($isSuccess && $subject && in_array($request->method(), ['PUT', 'PATCH'])) {
            try {
                $subject->refresh();
                $changes = $this->buildChangeDiff($originalData ?? [], $subject->getChanges());
                $changes = array_merge($changes, $this->detectRelationshipChanges($subject, $request));
            } catch (\Throwable) {
            }
        }

        try {
            $tenant = tenant();
            $logName = $isSystemRoute ? 'system' : ($tenant?->slug ?? 'tenant');
            $subjectLabel = $subject ? $this->labelFor($subject) : null;
            $description = $this->buildDescription($request, $user, $subjectLabel, $outcome, $exception, $changes);

            $this->withConnection(
                $isSystemRoute ? 'landlord' : null,
                function () use (
                    $logName, $description, $user, $subject,
                    $request, $payload, $outcome, $statusCode,
                    $changes, $subjectLabel, $exception, $tenant
                ) {
                    $activity = new Activity();
                    $activity->log_name = $logName;
                    $activity->description = $description;

                    if ($user) {
                        $activity->causer()->associate($user);
                    }

                    if ($subject) {
                        $activity->subject()->associate($subject);
                    }

                    $activity->properties = [
                        'user_name' => $user?->name,
                        'user_email' => $user?->email,
                        'user_type' => $user ? class_basename($user) : null,
                        'action' => $this->resolveAction($request, $changes),
                        'module' => $this->resolveModule($request, $subject),
                        'subject_label' => $subjectLabel,
                        'changes' => $changes,
                        'method' => $request->method(),
                        'url' => $request->fullUrl(),
                        'route' => $request->path(),
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'payload' => $payload,
                        'outcome' => $outcome,
                        'status_code' => $statusCode,
                        'error' => $exception?->getMessage(),
                        'tenant_id' => $tenant?->id,
                        'tenant_slug' => $tenant?->slug,
                    ];

                    $activity->save();
                }
            );
        } catch (\Throwable $e) {
            Log::error('LogActivity: failed to write activity log', [
                'error' => $e->getMessage(),
                'route' => $request->path(),
            ]);
        }

        if ($exception) {
            throw $exception;
        }

        return $response;
    }

    // ── Description ────────────────────────────────────────────────────────

    private function buildDescription(
        Request $request,
        ?object $user,
        ?string $subjectLabel,
        string $outcome,
        ?\Throwable $exception,
        array $changes,
    ): string {
        $actor = $user?->name ?? 'System';
        $action = $this->resolveAction($request, $changes);
        $module = $this->resolveModule($request, null);

        if ($outcome === 'success') {
            $base = $subjectLabel
                ? "{$module}: {$actor} {$action} {$subjectLabel}"
                : "{$module}: {$actor} {$action}";

            return mb_substr($base, 0, 2000);
        }

        $error = $exception ? " ({$exception->getMessage()})" : '';
        $base = $subjectLabel
            ? "{$module}: {$actor} attempted to {$action} {$subjectLabel} but failed{$error}"
            : "{$module}: {$actor} attempted to {$action} but failed{$error}";

        return mb_substr($base, 0, 2000);
    }

    // ── Action resolver ────────────────────────────────────────────────────

    private function resolveAction(Request $request, array $changes = []): string
    {
        $method = strtoupper($request->method());
        $path = $request->path();

        $patterns = [
            'terminate' => 'terminated',
            'reactivate' => 'reactivated',
            'approve' => 'approved',
            'reject' => 'rejected',
            'process' => 'processed',
            'reprocess' => 'reprocessed',
            'export-bank' => 'exported bank file for',
            'send-payslips' => 'sent payslips for',
            'reset-password' => 'reset password for',
            'toggle-active' => 'toggled status of',
            'assign-role' => 'assigned role on',
            'sync-permissions' => 'synced permissions on',
            'bulk-import' => 'bulk-imported',
            'provision' => 'provisioned database for',
            'set-primary' => 'set primary domain on',
            'toggle-module' => 'toggled module on',
            'read-all' => 'marked all notifications read',
            'complete' => 'completed onboarding task on',
            'waive' => 'waived onboarding task on',
            'close' => 'closed',
            'upload-logo' => 'uploaded logo for',
            'uploadLogo' => 'uploaded logo for',
        ];

        foreach ($patterns as $needle => $label) {
            if (str_contains($path, $needle)) {
                return $label;
            }
        }

        if (in_array($method, ['PUT', 'PATCH']) && !empty($changes)) {
            $fields = collect($changes)->pluck('field')->map(fn ($f) => strtolower($f));
            if ($fields->contains('status')) {
                return 'updated status of';
            }
            if ($fields->contains('password')) {
                return 'changed password of';
            }
            if ($fields->contains('email')) {
                return 'updated email of';
            }
        }

        return match ($method) {
            'POST' => 'created',
            'PUT', 'PATCH' => 'updated',
            'DELETE' => 'deleted',
            default => strtolower($method),
        };
    }

    // ── Module resolver ────────────────────────────────────────────────────

    private function resolveModule(Request $request, ?object $subject): string
    {
        $path = $request->path();

        return match (true) {
            str_contains($path, 'employees') => 'Employee Management',
            str_contains($path, 'payroll-cycles') => 'Payroll',
            str_contains($path, 'payslips') => 'Payroll',
            str_contains($path, 'pay-components') => 'Pay Components',
            str_contains($path, 'allowance') => 'Allowances',
            str_contains($path, 'deduction') => 'Deductions',
            str_contains($path, 'overtime') => 'Overtime',
            str_contains($path, 'onboarding') => 'Onboarding',
            str_contains($path, 'departments') => 'Organisation',
            str_contains($path, 'positions') => 'Organisation',
            str_contains($path, 'job-grades') => 'Organisation',
            str_contains($path, 'users') => 'User Management',
            str_contains($path, 'roles') => 'Role Management',
            str_contains($path, 'permissions') => 'Access Control',
            str_contains($path, 'settings') => 'Settings',
            str_contains($path, 'tenants') => 'Tenant Management',
            str_contains($path, 'subscriptions') => 'Subscriptions',
            str_contains($path, 'plans') => 'Plans',
            str_contains($path, 'features') => 'Features',
            str_contains($path, 'countries') => 'Country Config',
            str_contains($path, 'notifications') => 'Notifications',
            str_contains($path, 'reports') => 'Reports',
            default => $subject ? class_basename($subject) : 'System',
        };
    }

    // ── Subject from route binding ─────────────────────────────────────────

    private function resolveSubjectFromRoute(Request $request): ?object
    {
        $route = $request->route();
        if (!$route) {
            return null;
        }

        foreach ($route->parameters() as $param) {
            if (is_object($param) && method_exists($param, 'getKey')) {
                return $param;
            }
        }

        return null;
    }

    // ── Change diff ────────────────────────────────────────────────────────

    private function buildChangeDiff(array $original, array $changed): array
    {
        if (empty($changed)) {
            return [];
        }

        $diffs = [];

        foreach ($changed as $field => $newValue) {
            // Skip internal/hidden/large fields
            if (in_array($field, $this->skipFields, true)) {
                continue;
            }

            $oldValue = $original[$field] ?? null;

            if (!$this->isDifferent($oldValue, $newValue)) {
                continue;
            }

            // Never expose password hashes — just record that it changed
            if (in_array(strtolower($field), ['password', 'remember_token'], true)) {
                $diffs[] = [
                    'field' => $this->formatFieldName($field),
                    'old' => '[hidden]',
                    'new' => '[changed]',
                ];
                continue;
            }

            $diffs[] = [
                'field' => $this->formatFieldName($field),
                'old' => $this->formatValue($oldValue),
                'new' => $this->formatValue($newValue),
            ];
        }

        return $diffs;
    }

    private function detectRelationshipChanges(object $model, Request $request): array
    {
        $changes = [];

        if (method_exists($model, 'roles') && $request->has('roles')) {
            $old = $model->roles()->pluck('name')->sort()->values()->toArray();
            $new = collect($request->input('roles', []))->sort()->values()->toArray();
            if ($old !== $new) {
                $changes[] = [
                    'field' => 'Roles',
                    'old' => $old ? implode(', ', $old) : '(none)',
                    'new' => $new ? implode(', ', $new) : '(none)',
                ];
            }
        }

        if (method_exists($model, 'permissions') && $request->has('permissions')) {
            $old = $model->permissions()->pluck('name')->sort()->values()->toArray();
            $new = collect($request->input('permissions', []))->sort()->values()->toArray();
            if ($old !== $new) {
                $changes[] = [
                    'field' => 'Permissions',
                    'old' => $old ? implode(', ', $old) : '(none)',
                    'new' => $new ? implode(', ', $new) : '(none)',
                ];
            }
        }

        return $changes;
    }

    // ── Label ──────────────────────────────────────────────────────────────

    private function labelFor(object $model): string
    {
        $type = class_basename($model);
        $name = match (true) {
            method_exists($model, 'getDisplayNameAttribute') => $model->display_name,
            isset($model->name) => $model->name,
            isset($model->title) => $model->title,
            isset($model->email) => $model->email,
            default => null,
        };

        return $name ? "{$type}: {$name}" : "{$type} #{$model->getKey()}";
    }

    // ── Spatie connection swap ─────────────────────────────────────────────

    private function withConnection(?string $connection, callable $fn): void
    {
        $original = config('activitylog.database_connection');
        config(['activitylog.database_connection' => $connection]);
        try {
            $fn();
        } finally {
            config(['activitylog.database_connection' => $original]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function isDifferent(mixed $old, mixed $new): bool
    {
        if ($old === null && ($new === null || $new === '')) {
            return false;
        }
        if ($new === null && ($old === null || $old === '')) {
            return false;
        }
        if (is_bool($old) || is_bool($new)) {
            return (bool) $old !== (bool) $new;
        }
        if (is_array($old) || is_array($new)) {
            return json_encode($old) !== json_encode($new);
        }
        if (is_numeric($old) && is_numeric($new)) {
            return (string) $old !== (string) $new;
        }

        return trim((string) $old) !== trim((string) $new);
    }

    private function formatFieldName(string $field): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $field));
    }

    private function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return '(empty)';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            $json = json_encode($value);

            // Truncate large JSON (e.g. stancl's internal data blob)
            return mb_strlen($json) > 120 ? mb_substr($json, 0, 117).'…' : $json;
        }
        $str = (string) $value;

        return mb_strlen($str) > 120 ? mb_substr($str, 0, 117).'…' : $str;
    }

    private function sanitizePayload(array $payload): array
    {
        array_walk_recursive($payload, function (&$value, $key) {
            if (in_array(strtolower($key), $this->sensitiveFields, true)) {
                $value = '[REDACTED]';
            }
        });

        return $payload;
    }

    private function shouldSkip(Request $request): bool
    {
        $path = $request->path();
        foreach ($this->skipRoutes as $pattern) {
            $regex = '#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
}