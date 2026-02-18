<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/login', [Api\AuthController::class, 'login']);

// WhatsApp Webhook (public endpoints)
Route::prefix('webhooks/whatsapp')->group(function () {
    Route::get('/', [Api\WebhookController::class, 'verifyWhatsApp']);
    Route::post('/', [Api\WebhookController::class, 'handleWhatsApp']);
});
Route::get('/whatsapp/test-account', [WhatsAppController::class, 'getTestAccount']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {

    // Auth & Profile
    Route::post('/logout', [Api\AuthController::class, 'logout']);
    Route::get('/me', [Api\AuthController::class, 'me']);
    Route::put('/profile', [Api\AuthController::class, 'updateProfile']);
    Route::post('/password', [Api\AuthController::class, 'changePassword']);
    Route::post('/switch-tenant', [Api\AuthController::class, 'switchTenant']);

    // Super Admin Routes
    Route::prefix('admin')->middleware(['super-admin'])->group(function () {
        Route::apiResource('tenants', Api\TenantController::class);
        Route::post('tenants/{tenant}/activate', [Api\TenantController::class, 'activate']);
        Route::post('tenants/{tenant}/deactivate', [Api\TenantController::class, 'deactivate']);
        Route::get('statistics', [Api\TenantController::class, 'statistics']);
    });

    // Tenant Routes (requires tenant context)
    Route::middleware(['tenant'])->group(function () {

        // Dashboard
        Route::get('dashboard/stats', [Api\DashboardController::class, 'stats']);

        // WhatsApp Accounts
        Route::prefix('whatsapp')->group(function () {
            Route::get('accounts', [Api\WhatsAppAccountController::class, 'index']);
            Route::get('accounts/{account}', [Api\WhatsAppAccountController::class, 'show']);
            Route::get('signup-url', [Api\WhatsAppAccountController::class, 'getSignupUrl']);
            Route::post('callback', [Api\WhatsAppAccountController::class, 'handleCallback']);
            Route::delete('accounts/{account}', [Api\WhatsAppAccountController::class, 'disconnect']);
            Route::post('accounts/{account}/reconnect', [Api\WhatsAppAccountController::class, 'reconnect']);
            Route::post('accounts/{account}/sync', [Api\WhatsAppAccountController::class, 'sync']);
            Route::put('accounts/{account}', [Api\WhatsAppAccountController::class, 'update']);
            Route::get('accounts/{account}/health', [Api\WhatsAppAccountController::class, 'health']);
        });

        // Flow Routes
        // Route::get('/flows', [Api\FlowController::class, 'index']);
        // Route::post('/flows', [Api\FlowController::class, 'store']);
        // Route::get('/', [Api\FlowController::class, 'show']);
        // Route::put('/flows/{flow}', [Api\FlowController::class, 'update']);
        // Route::delete('/flows/{flow}', [Api\FlowController::class, 'destroy']);

        Route::apiResource('flows', Api\FlowController::class);
        Route::prefix('flows/{flow}')->group(function () {

            // Save flow (nodes + edges)
            Route::post('/save', [Api\FlowController::class, 'saveFlow']);

            // Publish flow
            Route::post('/publish', [Api\FlowController::class, 'publish']);
            Route::post('/unpublish', [Api\FlowController::class, 'unpublish']);
            Route::post('/duplicate', [Api\FlowController::class, 'duplicate']);

            // Get variables
            Route::get('/variables', [Api\FlowController::class, 'getVariables']);
        });

        // Custom Functions
        Route::prefix('custom-functions')->group(function () {
            Route::get('/', [Api\CustomFunctionController::class, 'index']);
            Route::post('/', [Api\CustomFunctionController::class, 'store']);
            Route::get('/{function}', [Api\CustomFunctionController::class, 'show']);
            Route::put('/{function}', [Api\CustomFunctionController::class, 'update']);
            Route::delete('/{function}', [Api\CustomFunctionController::class, 'destroy']);
            Route::post('/{function}/test', [Api\CustomFunctionController::class, 'test']);
            Route::get('/{function}/usage', [Api\CustomFunctionController::class, 'usage']);
        });

        // API Integrations
        Route::prefix('api-integrations')->group(function () {
            Route::get('/', [Api\APIIntegrationController::class, 'index']);
            Route::post('/', [Api\APIIntegrationController::class, 'store']);
            Route::get('/{integration}', [Api\APIIntegrationController::class, 'show']);
            Route::put('/{integration}', [Api\APIIntegrationController::class, 'update']);
            Route::delete('/{integration}', [Api\APIIntegrationController::class, 'destroy']);
            Route::post('/{integration}/test', [Api\APIIntegrationController::class, 'test']);
            Route::get('/{integration}/usage', [Api\APIIntegrationController::class, 'usage']);
        });

        // Variables
        Route::prefix('chatbots/{chatbot}/variables')->group(function () {
            Route::get('/', [Api\VariableController::class, 'indexChatbotVariables']);
            Route::post('/', [Api\VariableController::class, 'storeChatbotVariable']);
            Route::put('{variable}', [Api\VariableController::class, 'updateChatbotVariable']);
            Route::delete('{variable}', [Api\VariableController::class, 'destroyChatbotVariable']);
        });

        Route::prefix('global-variables')->group(function () {
            Route::get('/', [Api\VariableController::class, 'indexGlobalVariables']);
            Route::post('/', [Api\VariableController::class, 'storeGlobalVariable']);
            Route::put('{variable}', [Api\VariableController::class, 'updateGlobalVariable']);
            Route::delete('{variable}', [Api\VariableController::class, 'destroyGlobalVariable']);
        });

        // Message Templates
        Route::apiResource('templates', Api\MessageTemplateController::class);

        // Conversations
        Route::prefix('conversations')->group(function () {
            Route::get('/', [Api\ConversationController::class, 'index']);
            Route::get('{conversation}', [Api\ConversationController::class, 'show']);
            Route::get('{conversation}/messages', [Api\ConversationController::class, 'messages']);
            Route::post('{conversation}/handoff', [Api\ConversationController::class, 'handoff']);
            Route::post('{conversation}/end', [Api\ConversationController::class, 'end']);
            Route::delete('{conversation}', [Api\ConversationController::class, 'destroy']);
            Route::post('export', [Api\ConversationController::class, 'export']);
            Route::get('statistics', [Api\ConversationController::class, 'statistics']);
        });

        // Analytics
        Route::prefix('analytics')->group(function () {
            Route::get('overview', [Api\AnalyticsController::class, 'overview']);
            Route::get('chatbot/{chatbot}', [Api\AnalyticsController::class, 'chatbot']);
            Route::get('chatbot/{chatbot}/popular-paths', [Api\AnalyticsController::class, 'popularPaths']);
            Route::get('chatbot/{chatbot}/drop-off-points', [Api\AnalyticsController::class, 'dropOffPoints']);
            Route::post('export', [Api\AnalyticsController::class, 'export']);
        });

        // Team Management
        Route::prefix('team')->group(function () {
            Route::get('users', [Api\TeamController::class, 'index']);
            Route::post('invite', [Api\TeamController::class, 'invite']);
            Route::put('users/{user}/role', [Api\TeamController::class, 'updateRole']);
            Route::delete('users/{user}', [Api\TeamController::class, 'remove']);
        });

        // Settings
        Route::prefix('settings')->group(function () {
            Route::get('/', [Api\SettingsController::class, 'index']);
            Route::put('/', [Api\SettingsController::class, 'update']);
        });
    });
});