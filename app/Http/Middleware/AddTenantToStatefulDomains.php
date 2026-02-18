<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

class AddTenantToStatefulDomains
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Get the current host
        $host = $request->getHost();

        // Add current host to Sanctum's stateful domains if not already there
        $statefulDomains = config('sanctum.stateful', []);

        if (!in_array($host, $statefulDomains) && !in_array('*', $statefulDomains)) {
            $statefulDomains[] = $host;

            // Also add with port if present
            if ($request->getPort() && !in_array($host . ':' . $request->getPort(), $statefulDomains)) {
                $statefulDomains[] = $host . ':' . $request->getPort();
            }

            config(['sanctum.stateful' => $statefulDomains]);
        }

        return $next($request);
    }
}