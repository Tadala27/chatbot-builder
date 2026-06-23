<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    // ── Global middleware — runs on every request ─────────────────────────────
    protected $middleware = [
        Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    // ── Middleware groups ─────────────────────────────────────────────────────
    protected $middlewareGroups = [
        'web' => [
            Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        // ── Central API (/api/*) ──────────────────────────────────────────────
        'api' => [
            Middleware\ForceJsonResponse::class,
            Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            Middleware\AuthenticateSession::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            Middleware\LogActivity::class,
        ],

        // ── Tenant API (/tenant/*) ────────────────────────────────────────────
        //
        'tenant' => [
            Middleware\ForceJsonResponse::class,
            Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            Middleware\AuthenticateSession::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            Middleware\LogActivity::class,
        ],
    ];

    // ── Route middleware aliases ───────────────────────────────────────────────
    protected $middlewareAliases = [
        // Laravel built-ins
        'auth' => Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,

        // Spatie Permission
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

        // Stancl Tenancy
        'tenancy' => \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
        'tenant.subdomain' => \Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain::class,
        'prevent.central' => \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,

        // App-specific — kept as opt-in aliases, not in the default group stack
        'domain.redirect' => Middleware\RedirectByDomain::class,
        'check.user.type' => Middleware\CheckUserType::class,
        'auth.system' => Middleware\AuthenticateSystemGuard::class,
        'auth.tenant' => Middleware\AuthenticateTenantGuard::class,
        'connector.auth' => Middleware\ResolveTenantFromConnectorKey::class,
    ];

    // ── Middleware priority ───────────────────────────────────────────────────
    //
    // CRITICAL: tenancy middleware MUST run before StartSession. If session
    // starts first it opens on the wrong (landlord) database, the session
    // row isn't found there, and every request after login looks
    // unauthenticated.
    protected $middlewarePriority = [
        \Illuminate\Http\Middleware\HandleCors::class,
        Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,

        \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
        \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
        \Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain::class,
        \Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain::class,
        \Stancl\Tenancy\Middleware\InitializeTenancyByPath::class,
        \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class,

        \Illuminate\Session\Middleware\StartSession::class,

        \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Spatie\Permission\Middleware\PermissionMiddleware::class,
        \Spatie\Permission\Middleware\RoleMiddleware::class,
    ];
}
