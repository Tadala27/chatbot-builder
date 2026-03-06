<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiIntegrationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomFunctionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FlowBuilderController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\MediaUploadController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\VariableController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WhatsAppAccountController;
use Illuminate\Support\Facades\Route;

// =============================================================================
// PUBLIC — No authentication required
// =============================================================================

// WhatsApp webhook (Facebook calls these directly)
Route::prefix('webhook')->group(function () {
    Route::get('whatsapp',  [WebhookController::class, 'verifyWhatsApp']);
    Route::post('whatsapp', [WebhookController::class, 'handleWhatsApp']);
});

// Auth — unauthenticated endpoints
Route::prefix('auth')->group(function () {
    Route::post('login',                [AuthController::class, 'login']);
    Route::post('password/reset-link',  [AuthController::class, 'sendResetLink']);
    Route::post('password/reset',       [AuthController::class, 'resetPassword']);
});

// =============================================================================
// PROTECTED — Requires Sanctum token
// =============================================================================

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    // -------------------------------------------------------------------------
    // Auth / Session
    // -------------------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        Route::post('logout',               [AuthController::class, 'logout']);
        Route::get('me',                  [AuthController::class, 'user']);
        Route::get('profile',               [AuthController::class, 'profile']);
        Route::put('profile',               [AuthController::class, 'updateProfile']);
        Route::put('password',              [AuthController::class, 'updatePassword']);
        Route::post('password/force-reset', [AuthController::class, 'forceResetPassword']);
        Route::post('switch-tenant',        [AuthController::class, 'switchTenant']);
    });

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------
    Route::get('settings',  [SettingsController::class, 'index']);
    Route::put('settings',  [SettingsController::class, 'update']);

    // -------------------------------------------------------------------------
    // Team
    // -------------------------------------------------------------------------
    Route::prefix('team')->group(function () {
        Route::get('/',                     [TeamController::class, 'index']);
        Route::post('invite',               [TeamController::class, 'invite']);
        Route::put('{user}/role',           [TeamController::class, 'updateRole']);
        Route::delete('{user}',             [TeamController::class, 'remove']);
    });

    // -------------------------------------------------------------------------
    // WhatsApp Accounts  (tenant-scoped)
    // -------------------------------------------------------------------------
    Route::prefix('whatsapp-accounts')->group(function () {
        Route::get('/',                             [WhatsAppAccountController::class, 'index']);
        Route::get('signup-url',                    [WhatsAppAccountController::class, 'getSignupUrl']);
        Route::post('callback',                     [WhatsAppAccountController::class, 'handleCallback']);
        Route::get('{account}',                     [WhatsAppAccountController::class, 'show']);
        Route::put('{account}',                     [WhatsAppAccountController::class, 'update']);
        Route::post('{account}/disconnect',         [WhatsAppAccountController::class, 'disconnect']);
        Route::post('{account}/reconnect',          [WhatsAppAccountController::class, 'reconnect']);
        Route::post('{account}/sync',               [WhatsAppAccountController::class, 'sync']);
        Route::get('{account}/health',              [WhatsAppAccountController::class, 'health']);
    });

    // -------------------------------------------------------------------------
    // Bots  (tenant → whatsapp_account → bot)
    // -------------------------------------------------------------------------
    Route::apiResource('bots', BotController::class);

    // -------------------------------------------------------------------------
    // Flows  (nested under bots)
    // -------------------------------------------------------------------------
    Route::prefix('bots/{bot}')->group(function () {

        // Flow CRUD
        Route::get('flows',                         [FlowController::class, 'index']);
        Route::post('flows',                        [FlowController::class, 'store']);
        Route::get('flows/{flow}',                  [FlowController::class, 'show']);
        Route::put('flows/{flow}',                  [FlowController::class, 'update']);
        Route::delete('flows/{flow}',               [FlowController::class, 'destroy']);
        Route::post('flows/{flow}/unpublish',       [FlowController::class, 'unpublish']);
        Route::post('flows/{flow}/duplicate',       [FlowController::class, 'duplicate']);
        Route::get('/media',           [MediaUploadController::class, 'index']);

        // Flow Builder
        Route::prefix('flows/{flow}/builder')->group(function () {
            Route::get('/',                         [FlowBuilderController::class, 'show']);
            Route::post('save',                     [FlowBuilderController::class, 'autoSave']);
            Route::post('publish',                  [FlowBuilderController::class, 'publish']);
            Route::get('variables',                 [FlowBuilderController::class, 'getVariables']);
            Route::get('versions',                  [FlowBuilderController::class, 'getVersions']);
            Route::post('versions',                 [FlowBuilderController::class, 'createVersion']);
            Route::get('versions/{version}',        [FlowBuilderController::class, 'getVersion']);
        });

        // Flow Analytics
        Route::prefix('flows/{flow}/analytics')->group(function () {
            Route::get('/',                         [AnalyticsController::class, 'flow']);
            Route::get('paths',                     [AnalyticsController::class, 'popularPathsEndpoint']);
            Route::get('drop-offs',                 [AnalyticsController::class, 'dropOffPointsEndpoint']);
        });

        // Bot-scoped Variables (shared across all flows on this bot)
        Route::get('variables',                     [VariableController::class, 'index']);
        Route::post('variables',                    [VariableController::class, 'store']);
        Route::put('variables/{variable}',          [VariableController::class, 'update']);
        Route::delete('variables/{variable}',       [VariableController::class, 'destroy']);

        // Bot-scoped Custom Functions
        Route::get('functions',                     [CustomFunctionController::class, 'index']);
        Route::post('functions',                    [CustomFunctionController::class, 'store']);
        Route::get('functions/{function}',          [CustomFunctionController::class, 'show']);
        Route::put('functions/{function}',          [CustomFunctionController::class, 'update']);
        Route::delete('functions/{function}',       [CustomFunctionController::class, 'destroy']);
        Route::post('functions/{function}/test',    [CustomFunctionController::class, 'test']);

        // Bot-scoped API Integrations
        Route::get('apis',                          [ApiIntegrationController::class, 'index']);
        Route::post('apis',                         [ApiIntegrationController::class, 'store']);
        Route::get('apis/{api}',                    [ApiIntegrationController::class, 'show']);
        Route::put('apis/{api}',                    [ApiIntegrationController::class, 'update']);
        Route::delete('apis/{api}',                 [ApiIntegrationController::class, 'destroy']);
        Route::post('apis/{api}/test',              [ApiIntegrationController::class, 'test']);
    });

    // -------------------------------------------------------------------------
    // Conversations  (tenant-scoped; flow is a filter, not a route parent)
    // -------------------------------------------------------------------------
    Route::prefix('conversations')->group(function () {
        Route::get('/',                             [ConversationController::class, 'index']);
        Route::get('export',                        [ConversationController::class, 'export']);
        Route::get('statistics',                    [ConversationController::class, 'statistics']);
        Route::get('{conversation}',                [ConversationController::class, 'show']);
        Route::get('{conversation}/messages',       [ConversationController::class, 'messages']);
        Route::post('{conversation}/handoff',       [ConversationController::class, 'handoff']);
        Route::post('{conversation}/end',           [ConversationController::class, 'end']);
        Route::delete('{conversation}',             [ConversationController::class, 'destroy']);
    });

    // -------------------------------------------------------------------------
    // Analytics (tenant-level overview + export)
    // -------------------------------------------------------------------------
    Route::prefix('analytics')->group(function () {
        Route::get('overview',                      [AnalyticsController::class, 'overview']);
        Route::get('export',                        [AnalyticsController::class, 'export']);
    });

    // -------------------------------------------------------------------------
    // Message Templates  (tenant-scoped)
    // -------------------------------------------------------------------------
    Route::apiResource('message-templates', MessageTemplateController::class);

    // -------------------------------------------------------------------------
    // Built-in Functions & Templates  (global, no bot scope needed)
    // -------------------------------------------------------------------------
    Route::get('built-in-functions',                [CustomFunctionController::class, 'builtInFunctions']);
    Route::get('function-templates',                [CustomFunctionController::class, 'templates']);

    // -------------------------------------------------------------------------
    // Super-Admin only routes
    // -------------------------------------------------------------------------
    Route::middleware('role:super-admin')->prefix('admin')->group(function () {

        Route::prefix('tenants')->group(function () {
            Route::get('/',                         [TenantController::class, 'index']);
            Route::post('/',                        [TenantController::class, 'store']);
            Route::get('statistics',                [TenantController::class, 'statistics']);
            Route::get('{tenant}',                  [TenantController::class, 'show']);
            Route::put('{tenant}',                  [TenantController::class, 'update']);
            Route::delete('{tenant}',               [TenantController::class, 'destroy']);
            Route::post('{tenant}/activate',        [TenantController::class, 'activate']);
            Route::post('{tenant}/deactivate',      [TenantController::class, 'deactivate']);
        });
    });
    Route::prefix('media')->group(function () {
        // ── Media uploads ─────────────────────────────────────────────────────────────
        Route::post('/upload',              [MediaUploadController::class, 'upload']);
        Route::delete('/{media}',           [MediaUploadController::class, 'destroy']);
    });
});
