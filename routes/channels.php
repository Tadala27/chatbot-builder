<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Per-tenant-database architecture: a tenant user is only ever
| authenticated within ONE tenant's database connection. There is no
| tenant_id column on Conversation and no cross-tenant relation to check —
| Conversation::find() can only ever resolve a row belonging to the same
| tenant the authenticated user belongs to, because no other tenant's data
| is reachable from this connection at all. Existence IS the authorization.
*/

Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId): bool {
    return Conversation::where('id', $conversationId)->exists();
});

/*
 * Fixed channel name, no {tenantId} parameter — only one tenant's inbox
 * is ever reachable from a given connection, nothing to disambiguate.
 */
Broadcast::channel('tenant.inbox', function ($user): bool {
    return (bool) $user;
});
