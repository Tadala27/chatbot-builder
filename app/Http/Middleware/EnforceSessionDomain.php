<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        // Derive a safe prefix from the request host
        // e.g. "copperbelt-zm.payroll.test" → "copperbelt_zm_payroll_test"
        $prefix = preg_replace('/[^a-z0-9]/', '_', strtolower($request->getHost()));
        config(['session.prefix' => $prefix]);

        return $next($request);
    }
}