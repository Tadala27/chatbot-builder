<?php

// app/Http/Middleware/SetSystemGuardForPermissions.php

namespace App\Http\Middleware;

class SetSystemGuardForPermissions
{
    public function handle($request, \Closure $next)
    {
        // Override the default guard for permission checks
        config(['permission.guard_name' => 'system']);

        return $next($request);
    }
}