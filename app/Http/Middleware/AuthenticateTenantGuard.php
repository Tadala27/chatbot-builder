<?php
// app/Http/Middleware/AuthenticateTenantGuard.php
//
// Guards /tenant/* routes.
// Rejects any session that:
//   - has no user_id / user_type
//   - has user_type != 'tenant'
//   - was created on a different tenant (tenant_id mismatch)
//   - is a system admin session trying to hit tenant routes

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AuthenticateTenantGuard
{
    public function handle(Request $request, \Closure $next)
    {
        // Reject system admin sessions on tenant routes
        if (Session::get('user_type') === 'system') {
            return response()->json([
                'message' => 'Tenant routes cannot be accessed with a system admin session.',
                'code'    => 'SESSION_TYPE_MISMATCH',
            ], 401);
        }

        $userId   = Session::get('user_id');
        $userType = Session::get('user_type');

        if (! $userId || $userType !== 'tenant') {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        // Tenant session must match the current tenant context
        $currentTenant = tenant();
        if ($currentTenant) {
            $sessionTenantId = Session::get('tenant_id');
            if ($sessionTenantId && $sessionTenantId !== $currentTenant->id) {
                // Session belongs to a different tenant — reject
                return response()->json([
                    'message' => 'Session does not belong to this tenant.',
                    'code'    => 'TENANT_MISMATCH',
                ], 401);
            }
        }

        if (! Auth::guard('tenant')->check()) {
            $user = \App\Models\User::find($userId);
            if (! $user) {
                return response()->json(['message' => 'User not found.', 'code' => 'USER_NOT_FOUND'], 401);
            }
            if (! $user->is_active) {
                return response()->json(['message' => 'Your account is disabled.', 'code' => 'USER_INACTIVE'], 403);
            }
            Auth::guard('tenant')->login($user);
        }

        return $next($request);
    }
}