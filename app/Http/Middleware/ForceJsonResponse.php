<?php

// app/Http/Middleware/ForceJsonResponse.php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces the Accept header to application/json for all API and tenant routes.
 *
 * Without this, if the client omits the Accept header, Laravel may return
 * an HTML error page (e.g. for 404s, 500s, or validation errors) instead of
 * a JSON response, which breaks axios error handling in Vue.
 */
class ForceJsonResponse
{
    public function handle(Request $request, \Closure $next): Response
    {
        // Cover both central API routes and tenant API routes
        if ($request->is('api/*') || $request->is('tenant/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
