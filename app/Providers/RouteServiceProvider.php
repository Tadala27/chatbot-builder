<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->routes(function () {
            // ── Central API (/api/*) ─────────────────────────────────────
            Route::middleware('api')
                 ->prefix('api')
                 ->group(base_path('routes/api.php'));

            // ── Tenant API (/tenant/*) ───────────────────────────────────
            // InitializeTenancyByDomain MUST be before StartSession
            Route::middleware([
                \App\Http\Middleware\ForceJsonResponse::class,
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
                \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \App\Http\Middleware\AuthenticateSession::class,
                \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
                 ->prefix('tenant')
                 ->group(base_path('routes/tenant.php'));

            // ── Web ──────────────────────────────────────────────────────
            Route::middleware('web')
                 ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
