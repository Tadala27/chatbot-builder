<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Super admins can bypass tenant requirement
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Get user's primary tenant or first available tenant
        $tenant = $user->getPrimaryTenant() ?? $user->tenants()->first();

        if (!$tenant) {
            return response()->json([
                'message' => 'No tenant associated with user',
            ], 403);
        }

        // Check if tenant is active
        if (!$tenant->is_active) {
            return response()->json([
                'message' => 'Tenant account is inactive',
            ], 403);
        }

        // Check subscription status
        if (!$tenant->isSubscriptionActive()) {
            return response()->json([
                'message' => 'Subscription has expired',
            ], 403);
        }

        // Ensure the current domain matches the tenant's domain (optional security)
        $currentHost = $request->getHost();
        $expectedHost = parse_url($tenant->domain, PHP_URL_HOST) ?? $tenant->domain;

        if ($currentHost !== $expectedHost && !$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Invalid tenant domain',
                'expected' => $expectedHost,
                'current' => $currentHost
            ], 403);
        }

        // Set current tenant
        $tenant->makeCurrent();

        // Add tenant to response headers for debugging (optional)
        $response = $next($request);
        $response->headers->set('X-Tenant-ID', $tenant->id);
        $response->headers->set('X-Tenant-Domain', $tenant->domain);

        return $response;
    }
}
