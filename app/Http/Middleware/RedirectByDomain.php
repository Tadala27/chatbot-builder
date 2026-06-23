<?php

// app/Http/Middleware/RedirectByDomain.php
//
// Enforces login page separation by domain type:
//
//   Central domain (payroll.test):
//     /login         → redirect to /system-login
//     /              → redirect to /system-login
//
//   Tenant subdomain (*.payroll.test):
//     /system-login  → redirect to /login
//     /admin/*       → redirect to /login
//
// Register as a web middleware alias in bootstrap/app.php:
//   $middleware->alias(['domain.redirect' => \App\Http\Middleware\RedirectByDomain::class]);
//
// Then apply it on the relevant web routes (see web.php changes below).
// Alternatively, add it to the global 'web' group so it fires on every request.

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectByDomain
{
    public function handle(Request $request, \Closure $next): Response
    {
        $host = $request->getHost();

        $isCentral = in_array(
            $host,
            config('tenancy.central_domains', []),
            true
        );

        $path = trim($request->path(), '/');

        $centralAllowed = [
            '',
            'system-login',
            'reset-password',
            'unauthorized',
            'logs',
            'landing',
        ];

        if ($isCentral) {
            // Central domains may only access:
            // - /admin/*
            // - routes in $centralAllowed

            if (
                !in_array($path, $centralAllowed, true)
                && !str_starts_with($path, 'admin')
            ) {
                return redirect('/system-login');
            }

            // Prevent use of tenant login on central domains
            if ($path === 'login') {
                return redirect('/system-login');
            }
        } else {
            // Tenant domains cannot access central-only routes

            if (
                $path === 'system-login'
                || $path === 'landing'
                || str_starts_with($path, 'admin')
            ) {
                return redirect('/login');
            }
        }

        return $next($request);
    }
}
