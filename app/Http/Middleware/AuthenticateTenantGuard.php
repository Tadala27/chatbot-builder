<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\PermissionRegistrar;

class AuthenticateTenantGuard
{
    public function handle(Request $request, \Closure $next)
    {
        if (Session::get('user_type') === 'system') {
            return response()->json([
                'message' => 'Tenant routes cannot be accessed with a system admin session.',
                'code' => 'SESSION_TYPE_MISMATCH',
            ], 401);
        }

        $userId = Session::get('user_id');
        $userType = Session::get('user_type');

        if (!$userId || $userType !== 'tenant') {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $currentTenant = tenant();
        if ($currentTenant) {
            $sessionTenantId = Session::get('tenant_id');
            if ($sessionTenantId && $sessionTenantId !== $currentTenant->id) {
                return response()->json([
                    'message' => 'Session does not belong to this tenant.',
                    'code' => 'TENANT_MISMATCH',
                ], 401);
            }
        }

        if (!Auth::guard('tenant')->check()) {
            $user = User::find($userId);
            if (!$user) {
                return response()->json(['message' => 'User not found.', 'code' => 'USER_NOT_FOUND'], 401);
            }
            if (!$user->is_active) {
                return response()->json(['message' => 'Your account is disabled.', 'code' => 'USER_INACTIVE'], 403);
            }
            Auth::guard('tenant')->login($user);
        }

        Auth::shouldUse('tenant');
        config(['permission.guard_name' => 'tenant']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $next($request);
    }
}