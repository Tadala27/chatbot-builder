<?php

// app/Http/Middleware/AuthenticateSession.php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates that the session is internally consistent:
 *
 *   - user_id and user_type are present
 *   - the correct guard is authenticated (re-logs the user in if not, since
 *     Laravel's session guard may not auto-restore on the first request)
 *
 * This middleware does NOT touch the database connection or tenancy state.
 * By the time it runs, stancl's InitializeTenancyByDomain has already switched
 * the default connection to the tenant DB, and StartSession has opened the
 * correct session from that DB.
 *
 * DO NOT re-implement tenancy DB switching here — it will conflict with stancl.
 */
class AuthenticateSession
{
    public function handle(Request $request, \Closure $next): Response
    {
        $userType = Session::get('user_type');
        $userId = Session::get('user_id');

        // No session data → nothing to restore, let the auth middleware handle it
        if (!$userId || !$userType) {
            return $next($request);
        }

        $guard = $userType === 'system' ? 'system' : 'tenant';

        // If the guard already has a user (Laravel's session guard auto-restored
        // it from the session), there is nothing more to do.
        if (Auth::guard($guard)->check()) {
            return $next($request);
        }

        // Guard not yet authenticated on this request — restore from session.
        // For the 'tenant' guard this works because by this point the tenant DB
        // is active (stancl has already bootstrapped) so User::find() hits the
        // right database.
        $model = $guard === 'system'
            ? \App\Models\SystemUser::find($userId)
            : \App\Models\User::find($userId);

        if (!$model || !$model->is_active) {
            // User no longer exists or has been disabled — invalidate the session
            Session::flush();

            return response()->json(['message' => 'Session expired.', 'code' => 'SESSION_EXPIRED'], 401);
        }

        Auth::guard($guard)->login($model);

        return $next($request);
    }
}
