<?php

// app/Services/TenantNotificationService.php

namespace App\Services\Tenant;

use App\Events\NotificationSent;
use App\Mail\NotificationMail;
use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantNotificationService
{
    public static function notify(
        string $permission,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        int $expiresInDays = 7,
    ): void {
        $userIds = static::usersWithPermission($permission);

        if ($userIds->isEmpty()) {
            static::broadcastFallback($type, $title, $message, $actionUrl, $actionLabel, $expiresInDays);

            return;
        }

        static::createAndDispatch($userIds, $type, $title, $message, $actionUrl, $actionLabel, $expiresInDays);
    }

    public static function notifyAny(
        array $permissions,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        int $expiresInDays = 7,
    ): void {
        $userIds = collect($permissions)
            ->flatMap(fn ($p) => static::usersWithPermission($p))
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            static::broadcastFallback($type, $title, $message, $actionUrl, $actionLabel, $expiresInDays);

            return;
        }

        static::createAndDispatch($userIds, $type, $title, $message, $actionUrl, $actionLabel, $expiresInDays);
    }

    // ── Core ──────────────────────────────────────────────────────────────

    private static function createAndDispatch(
        \Illuminate\Support\Collection $userIds,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl,
        ?string $actionLabel,
        int $expiresInDays,
    ): void {
        $expiresAt = now()->addDays($expiresInDays);
        $now = now();
        $createdBy = auth()->guard('tenant')->id() ?? auth()->guard('system')->id();

        $rows = $userIds->map(fn ($userId) => [
            'user_id' => $userId,
            'user_type' => 'tenant',
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'action_label' => $actionLabel,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray();

        DB::table('notifications')->insert($rows);

        $inserted = DB::table('notifications')
            ->whereIn('user_id', $userIds->toArray())
            ->where('created_at', $now)
            ->where('title', $title)
            ->get(['id', 'user_id']);

        // Resolve type helpers ONCE outside the loop
        $typeColor = static::typeColor($type);
        $typeIcon = static::typeIcon($type);

        $users = User::whereIn('id', $userIds->toArray())
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        foreach ($users as $user) {
            $notifRow = $inserted->firstWhere('user_id', $user->id);
            $notifId = (string) ($notifRow?->id ?? 0);

            // 1. Reverb broadcast
            broadcast(new NotificationSent(
                userId: $user->id,
                notifId: $notifId,
                type: $type,
                title: $title,
                message: $message,
                actionUrl: $actionUrl,
                actionLabel: $actionLabel,
            ))->toOthers();

            // 2. Queued email via NotificationMail
            try {
                TenantMailer::mailer()
                    ->to($user->email, $user->name)
                    ->queue(new NotificationMail(
                        mailView: 'emails.notification',
                        mailSubject: $title,
                        recipient: $user,
                        data: [
                            'notifType' => $type,
                            'notifTitle' => $title,
                            'notifMessage' => $message,
                            'actionUrl' => $actionUrl,
                            'actionLabel' => $actionLabel,
                            'typeColor' => $typeColor,
                            'typeIcon' => $typeIcon,
                        ],
                    ));
            } catch (\Throwable) {
            }
        }
    }

    private static function broadcastFallback(
        string $type,
        string $title,
        string $message,
        ?string $actionUrl,
        ?string $actionLabel,
        int $expiresInDays,
    ): void {
        TenantNotification::broadcast(
            type: $type,
            title: $title,
            message: $message,
            actionUrl: $actionUrl,
            actionLabel: $actionLabel,
            expiresInDays: $expiresInDays,
        );
    }

    private static function usersWithPermission(string $permission): \Illuminate\Support\Collection
    {
        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permission)
            ->where('permissions.guard_name', 'tenant')
            ->where('model_has_permissions.model_type', 'App\\Models\\User')
            ->pluck('model_has_permissions.model_id');

        $viaRole = DB::table('model_has_roles')
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permission)
            ->where('permissions.guard_name', 'tenant')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->pluck('model_has_roles.model_id');

        $actingUserId = auth()->guard('tenant')->id();

        return $direct->merge($viaRole)
            ->unique()
            ->when($actingUserId, fn ($c) => $c->reject(fn ($id) => $id === $actingUserId))
            ->values();
    }

    // ── Type helpers (used in emails) ─────────────────────────────────────

    public static function typeColor(string $type): string
    {
        return [
            'success' => '#16a34a',
            'warning' => '#d97706',
            'error' => '#dc2626',
            'alert' => '#7c3aed',
            'info' => '#5199ae',
        ][$type] ?? '#5199ae';
    }

    public static function typeIcon(string $type): string
    {
        return [
            'success' => '✓',
            'warning' => '⚠',
            'error' => '✕',
            'alert' => '!',
            'info' => 'ℹ',
        ][$type] ?? 'ℹ';
    }
}