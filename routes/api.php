<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PlatformAnalyticsController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\Admin\SystemLogController;
use App\Http\Controllers\Api\Admin\TenantController;
use App\Http\Controllers\Api\ConnectorController;
use App\Http\Middleware\ResolveTenantFromConnectorKey;
use Illuminate\Support\Facades\Route;

// =============================================================================
// PUBLIC
// =============================================================================

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('password/reset-link', [AuthController::class, 'sendResetLink']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
});
Route::prefix('webhook/connector')->group(function () {
    Route::get('/', [ConnectorController::class, 'verify']);
    Route::post('/', [ConnectorController::class, 'receive']);
});
Route::get('connector/media/{media_id}', [ConnectorController::class, 'streamMedia'])
    ->name('connector.media.stream')
    ->middleware('signed');
Route::post('connector/messages', [ConnectorController::class, 'send'])
    ->middleware(ResolveTenantFromConnectorKey::class);
// =============================================================================
// PROTECTED — Central admin users only
// =============================================================================

Route::middleware(['auth:system'])->group(function () {
    // ── Auth / Session ────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'user']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'updatePassword']);
    });
    Route::get('/admin/dashboard', [DashboardController::class, 'index']);
    // ── Tenant Management ─────────────────────────────────────────────────────
    Route::prefix('admin/tenants')->group(function () {
        Route::get('/', [TenantController::class, 'index'])
        ->middleware('permission:view tenants');

        Route::post('/', [TenantController::class, 'store'])
            ->middleware('permission:create tenants');

        Route::get('statistics', [TenantController::class, 'statistics'])
            ->middleware('permission:view tenants');

        Route::get('{tenant}', [TenantController::class, 'show'])
            ->middleware('permission:view tenants');

        Route::put('{tenant}', [TenantController::class, 'update'])
            ->middleware('permission:edit tenants');

        Route::delete('{tenant}', [TenantController::class, 'destroy'])
            ->middleware('permission:delete tenants');

        Route::post('{tenant}/activate', [TenantController::class, 'activate'])
            ->middleware('permission:edit tenants');

        Route::post('{tenant}/deactivate', [TenantController::class, 'deactivate'])
            ->middleware('permission:edit tenants');

        Route::post('{tenant}/impersonate', [TenantController::class, 'impersonate'])
            ->middleware('permission:impersonate tenant');
    });

    // ── Platform Settings ─────────────────────────────────────────────────────
    Route::prefix('admin/platform')->group(function () {
        Route::get('settings', [PlatformSettingsController::class, 'index'])
            ->middleware('permission:manage platform settings');

        Route::put('settings', [PlatformSettingsController::class, 'update'])
            ->middleware('permission:manage platform settings');

        Route::get('analytics', [PlatformAnalyticsController::class, 'index'])
            ->middleware('permission:view platform analytics');

        Route::get('logs', [SystemLogController::class, 'index'])
            ->middleware('permission:view system logs');
    });

    // ── Central Admin User Management ─────────────────────────────────────────
    Route::prefix('admin/users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])
            ->middleware('permission:view admin users');

        Route::post('/', [AdminUserController::class, 'store'])
            ->middleware('permission:create admin users');

        Route::put('{user}', [AdminUserController::class, 'update'])
            ->middleware('permission:edit admin users');

        Route::delete('{user}', [AdminUserController::class, 'destroy'])
            ->middleware('permission:delete admin users');

        Route::put('{user}/roles', [AdminUserController::class, 'assignRoles'])
            ->middleware('permission:assign admin roles');
    });
});