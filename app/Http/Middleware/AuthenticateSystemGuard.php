<?php
// app/Http/Middleware/AuthenticateSystemGuard.php
//
// Guards /api/admin/* routes.
// Rejects any session that:
//   - has no user_id / user_type
//   - has user_type != 'system'
//   - originated from a tenant subdomain (has tenant_id set)
//   - does not match the central domain

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AuthenticateSystemGuard
{
    public function handle(Request $request, \Closure $next)
    {
        // Reject if request is coming from a tenant subdomain.
        // Central routes should only be accessible from the central domain.
        $centralDomains = config('tenancy.central_domains', []);
        $host = $request->getHost();

        $isOnCentralDomain = collect($centralDomains)->contains(
            fn ($d) => $host === $d || str_ends_with($host, '.' . $d) === false
        );

        // More precise: if the host exactly matches a central domain → OK
        // If the host is a subdomain (e.g. copperbelt-zm.payroll.test) → reject
        $isCentralHost = in_array($host, $centralDomains, true);
        if (! $isCentralHost) {
            return response()->json([
                'message' => 'Admin routes are not accessible from tenant domains.',
                'code'    => 'WRONG_DOMAIN',
            ], 403);
        }

        $userId   = Session::get('user_id');
        $userType = Session::get('user_type');

        if (! $userId || $userType !== 'system') {
            return response()->json([
                'message' => 'Unauthenticated. System admin access required.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        // If a tenant_id is in this session, it's a tenant session leaked to /api
        if (Session::has('tenant_id')) {
            return response()->json([
                'message' => 'Cannot access admin routes with a tenant session.',
                'code'    => 'SESSION_TYPE_MISMATCH',
            ], 401);
        }

        if (! Auth::guard('system')->check()) {
            $user = \App\Models\SystemUser::find($userId);
            if (! $user) {
                return response()->json(['message' => 'System user not found.', 'code' => 'USER_NOT_FOUND'], 401);
            }
            if (! $user->is_active) {
                return response()->json(['message' => 'Your system account has been disabled.', 'code' => 'USER_INACTIVE'], 403);
            }
            Auth::guard('system')->login($user);
        }

        if (Auth::guard('system')->id() != $userId) {
            return response()->json(['message' => 'Session mismatch.', 'code' => 'SESSION_MISMATCH'], 401);
        }

        return $next($request);
    }
}