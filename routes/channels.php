<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Conversation channel  →  private-conversation.{conversationId}
 */
Broadcast::channel('conversation.{conversationId}', function ($user, int $conversationId): bool {
    $conversation = Conversation::find($conversationId);

    if (!$conversation) {
        Log::warning('[Channel Auth] Conversation not found', [
            'conversation_id' => $conversationId,
            'user_id'         => $user->id,
        ]);
        return false;
    }

    $userTenantIds = $user->tenants()->pluck('tenants.id')->toArray();
    $allowed       = in_array($conversation->tenant_id, $userTenantIds);

    Log::info('[Channel Auth] conversation channel', [
        'conversation_id'     => $conversationId,
        'conversation_tenant' => $conversation->tenant_id,
        'user_id'             => $user->id,
        'user_tenant_ids'     => $userTenantIds,
        'allowed'             => $allowed,
    ]);

    return $allowed;
});

/**
 * Tenant inbox channel  →  private-tenant.{tenantId}.inbox
 */
Broadcast::channel('tenant.{tenantId}.inbox', function ($user, int $tenantId): bool {
    $userTenantIds = $user->tenants()->pluck('tenants.id')->toArray();
    $allowed       = in_array($tenantId, $userTenantIds);

    Log::info('[Channel Auth] inbox channel', [
        'requested_tenant_id' => $tenantId,
        'user_id'             => $user->id,
        'user_tenant_ids'     => $userTenantIds,
        'allowed'             => $allowed,
    ]);

    return $allowed;
});
