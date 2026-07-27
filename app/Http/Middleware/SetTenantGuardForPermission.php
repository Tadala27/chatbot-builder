<?php

// app/Http/Middleware/SetTenantGuardForPermission.php

namespace App\Http\Middleware;

class SetTenantGuardForPermission
{
    public function handle($request, \Closure $next)
    {
        // Override the default guard for permission checks
        config(['permission.guard_name' => 'tenant']);

        return $next($request);
    }
}