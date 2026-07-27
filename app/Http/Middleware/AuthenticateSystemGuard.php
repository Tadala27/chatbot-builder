<?php

namespace App\Http\Middleware;

use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\PermissionRegistrar;

class AuthenticateSystemGuard
{
    public function handle(Request $request, \Closure $next)
    {
        // Central routes only — reject if coming from a tenant subdomain
        $centralDomains = config('tenancy.central_domains', []);
        $isCentralHost = in_array($request->getHost(), $centralDomains, true);

        if (!$isCentralHost) {
            return response()->json([
                'message' => 'Admin routes are not accessible from tenant domains.',
                'code' => 'WRONG_DOMAIN',
            ], 403);
        }

        $userId = Session::get('user_id');
        $userType = Session::get('user_type');

        if (!$userId || $userType !== 'system') {
            return response()->json([
                'message' => 'Unauthenticated. System admin access required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (Session::has('tenant_id')) {
            return response()->json([
                'message' => 'Cannot access admin routes with a tenant session.',
                'code' => 'SESSION_TYPE_MISMATCH',
            ], 401);
        }

        if (!Auth::guard('system')->check()) {
            $user = SystemUser::find($userId);
            if (!$user) {
                return response()->json(['message' => 'System user not found.', 'code' => 'USER_NOT_FOUND'], 401);
            }
            if (!$user->is_active) {
                return response()->json(['message' => 'Your system account has been disabled.', 'code' => 'USER_INACTIVE'], 403);
            }
            Auth::guard('system')->login($user);
        }

        if (Auth::guard('system')->id() != $userId) {
            return response()->json(['message' => 'Session mismatch.', 'code' => 'SESSION_MISMATCH'], 401);
        }

        Auth::shouldUse('system');

        // ── Permission guard: system users check permissions in the landlord DB
        // against the 'system' guard. Set this here so Spatie's 'permission'
        // middleware uses the right guard without needing a separate middleware.
        config(['permission.guard_name' => 'system']);

        // Flush Spatie's per-request cache so stale tenant permissions don't
        // bleed into system permission checks (important when both contexts
        // are hit in the same PHP process, e.g. during testing or queue work).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $next($request);
    }
}