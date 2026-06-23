<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as IlluminateAuthenticate;
use Illuminate\Http\Request;

class Authenticate extends IlluminateAuthenticate
{
    /**
     * Determine which guards to check for a given request.
     *
     * 1. If the route already declared guards (auth:tenant), honour them.
     * 2. Requests under /tenant/* → 'tenant' guard.
     * 3. Everything else          → 'system' guard.
     */
    protected function guards(Request $request, array $guards): array
    {
        if (!empty($guards)) {
            return $guards;
        }

        return $this->isTenantRequest($request) ? ['tenant'] : ['system'];
    }

    /**
     * Redirect unauthenticated browser requests to the correct login page.
     * Returning null tells Laravel to throw a 401 instead of redirecting
     * (used for JSON / API requests via the unauthenticated() override below).
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        return $this->isTenantRequest($request) ? '/login' : '/system-login';
    }

    /**
     * Override so that JSON requests always receive a 401 JSON response
     * rather than a 302 redirect, which Vue's axios interceptor can't handle.
     *
     * Return type omitted to stay compatible with the untyped parent signature.
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new AuthenticationException(message: 'Unauthenticated.', guards: $guards, redirectTo: $request->expectsJson() ? null : $this->redirectTo($request));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isTenantRequest(Request $request): bool
    {
        return str_starts_with($request->path(), 'tenant/');
    }
}
