<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiIntegrationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotConfigurationController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\BotDialogController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomFunctionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FlowBuilderController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\MediaUploadController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\VariableController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WhatsAppAccountController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// =============================================================================
// PUBLIC — No authentication required
// =============================================================================

// WhatsApp webhooks — Facebook calls these directly
Route::prefix('webhooks')->group(function () {
    Route::get('/whatsapp', [WebhookController::class, 'verifyWhatsApp']);
    Route::post('/whatsapp', [WebhookController::class, 'handleWhatsApp']);
});

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('password/reset-link', [AuthController::class, 'sendResetLink']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
});

// =============================================================================
// PROTECTED — Tenant users
// =============================================================================

Broadcast::routes(['middleware' => ['auth:tenant']]);

Route::middleware(['auth:tenant'])->group(function () {
    // ── Auth / Session ────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'user']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'updatePassword']);
        Route::post('password/force-reset', [AuthController::class, 'forceResetPassword']);
    });

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:view analytics');

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::get('settings', [SettingsController::class, 'index'])
        ->middleware('permission:view settings');

    Route::put('settings', [SettingsController::class, 'update'])
        ->middleware('permission:manage settings');

    // ── Team / User Management ────────────────────────────────────────────────
    Route::prefix('team')->group(function () {
        Route::get('/', [TeamController::class, 'index'])
            ->middleware('permission:view users');

        Route::post('invite', [TeamController::class, 'invite'])
            ->middleware('permission:invite users');

        Route::put('{user}/role', [TeamController::class, 'updateRole'])
            ->middleware('permission:assign roles');

        Route::delete('{user}', [TeamController::class, 'remove'])
            ->middleware('permission:delete users');
    });
   
    Route::prefix('whatsapp-accounts')->group(function () {
    Route::get('/', [WhatsAppAccountController::class, 'index'])
        ->middleware('permission:view whatsapp-accounts');

    // Must come before {account} routes below.
    Route::get('connector', [WhatsAppAccountController::class, 'connectorAccount'])
        ->middleware('permission:view whatsapp-accounts');

    Route::post('connect-connector', [WhatsAppAccountController::class, 'connectConnector'])
        ->middleware('permission:connect whatsapp-accounts');

    // Managed-bot OAuth flow — unrelated to connector mode.
    Route::get('signup-url', [WhatsAppAccountController::class, 'getSignupUrl'])
        ->middleware('permission:connect whatsapp-accounts');

    Route::post('callback', [WhatsAppAccountController::class, 'handleCallback'])
        ->middleware('permission:connect whatsapp-accounts');

    Route::get('{account}', [WhatsAppAccountController::class, 'show'])
        ->middleware('permission:view whatsapp-accounts');

    Route::put('{account}', [WhatsAppAccountController::class, 'update'])
        ->middleware('permission:manage whatsapp-accounts');

    Route::post('{account}/disconnect', [WhatsAppAccountController::class, 'disconnect'])
        ->middleware('permission:disconnect whatsapp-accounts');

    Route::post('{account}/reconnect', [WhatsAppAccountController::class, 'reconnect'])
        ->middleware('permission:connect whatsapp-accounts');

    Route::post('{account}/sync', [WhatsAppAccountController::class, 'sync'])
        ->middleware('permission:manage whatsapp-accounts');

    Route::get('{account}/health', [WhatsAppAccountController::class, 'health'])
        ->middleware('permission:view whatsapp-accounts');

    Route::post('{account}/rotate-connector-key', [WhatsAppAccountController::class, 'rotateConnectorKey'])
        ->middleware('permission:manage whatsapp-accounts');
});
    // ── Bots ──────────────────────────────────────────────────────────────────
    Route::get('bots', [BotController::class, 'index'])
        ->middleware('permission:view bots');

    Route::post('bots', [BotController::class, 'store'])
        ->middleware('permission:create bots');

    Route::get('bots/{bot}', [BotController::class, 'show'])
        ->middleware('permission:view bots');

    Route::put('bots/{bot}', [BotController::class, 'update'])
        ->middleware('permission:edit bots');

    Route::delete('bots/{bot}', [BotController::class, 'destroy'])
        ->middleware('permission:delete bots');

    // ── Bot sub-resources ─────────────────────────────────────────────────────
    Route::prefix('bots/{bot}')->group(function () {
        // Media
        Route::get('media', [MediaUploadController::class, 'index'])
            ->middleware('permission:view bots');

        Route::post('media', [MediaUploadController::class, 'upload'])
            ->middleware('permission:edit bots');

        Route::delete('media/{media}', [MediaUploadController::class, 'destroy'])
            ->middleware('permission:edit bots');

        // Bot Dialogs
        Route::get('bot-dialogs', [BotDialogController::class, 'index'])
            ->middleware('permission:view bots');

        Route::post('bot-dialogs', [BotDialogController::class, 'store'])
            ->middleware('permission:edit bots');

        Route::get('bot-dialogs/{botDialog}', [BotDialogController::class, 'show'])
            ->middleware('permission:view bots');

        Route::put('bot-dialogs/{botDialog}', [BotDialogController::class, 'update'])
            ->middleware('permission:edit bots');

        Route::delete('bot-dialogs/{botDialog}', [BotDialogController::class, 'destroy'])
            ->middleware('permission:edit bots');

        // Bot Configuration
        Route::get('configuration', [BotConfigurationController::class, 'show'])
            ->middleware('permission:view bots');

        Route::post('configuration', [BotConfigurationController::class, 'upsert'])
            ->middleware('permission:edit bots');

        // Flows
        Route::get('flows', [FlowController::class, 'index'])
            ->middleware('permission:view bots');

        Route::post('flows', [FlowController::class, 'store'])
            ->middleware('permission:edit flows');

        Route::get('flows/{flow}', [FlowController::class, 'show'])
            ->middleware('permission:view bots');

        Route::put('flows/{flow}', [FlowController::class, 'update'])
            ->middleware('permission:edit flows');

        Route::delete('flows/{flow}', [FlowController::class, 'destroy'])
            ->middleware('permission:delete nodes');

        Route::post('flows/{flow}/unpublish', [FlowController::class, 'unpublish'])
            ->middleware('permission:edit flows');

        Route::post('flows/{flow}/duplicate', [FlowController::class, 'duplicate'])
            ->middleware('permission:duplicate bots');

        // Flow Builder
        Route::prefix('flows/{flow}/builder')->group(function () {
            Route::get('/', [FlowBuilderController::class, 'show'])
                ->middleware('permission:edit flows');

            Route::post('save', [FlowBuilderController::class, 'autoSave'])
                ->middleware('permission:edit flows');

            Route::post('publish', [FlowBuilderController::class, 'publish'])
                ->middleware('permission:publish bots');

            Route::get('variables', [FlowBuilderController::class, 'getVariables'])
                ->middleware('permission:view variables');

            Route::get('functions', [FlowBuilderController::class, 'getFunctions'])
                ->middleware('permission:view functions');

            Route::get('versions', [FlowBuilderController::class, 'getVersions'])
                ->middleware('permission:edit flows');

            Route::post('versions', [FlowBuilderController::class, 'createVersion'])
                ->middleware('permission:edit flows');

            Route::get('versions/{version}', [FlowBuilderController::class, 'getVersion'])
                ->middleware('permission:edit flows');
        });

        // Flow Analytics
        Route::prefix('flows/{flow}/analytics')->group(function () {
            Route::get('/', [AnalyticsController::class, 'flow'])
                ->middleware('permission:view analytics');

            Route::get('paths', [AnalyticsController::class, 'popularPathsEndpoint'])
                ->middleware('permission:view detailed-analytics');

            Route::get('drop-offs', [AnalyticsController::class, 'dropOffPointsEndpoint'])
                ->middleware('permission:view detailed-analytics');
        });

        // Variables
        Route::prefix('variables')->group(function () {
            Route::get('/', [VariableController::class, 'index'])
                ->middleware('permission:view variables');

            Route::post('/', [VariableController::class, 'store'])
                ->middleware('permission:create variables');

            Route::put('{variable}', [VariableController::class, 'update'])
                ->middleware('permission:edit variables');

            Route::delete('{variable}', [VariableController::class, 'destroy'])
                ->middleware('permission:delete variables');
        });

        // Custom Functions
        Route::prefix('functions')->group(function () {
            Route::get('/', [CustomFunctionController::class, 'index'])
                ->middleware('permission:view functions');

            Route::post('/', [CustomFunctionController::class, 'store'])
                ->middleware('permission:create functions');

            Route::get('{function}', [CustomFunctionController::class, 'show'])
                ->middleware('permission:view functions');

            Route::put('{function}', [CustomFunctionController::class, 'update'])
                ->middleware('permission:edit functions');

            Route::delete('{function}', [CustomFunctionController::class, 'destroy'])
                ->middleware('permission:delete functions');

            Route::post('{function}/test', [CustomFunctionController::class, 'test'])
                ->middleware('permission:test functions');

            Route::post('test-draft', [CustomFunctionController::class, 'testDraft'])
                ->middleware('permission:test functions');
        });

        // API Integrations
        Route::prefix('apis')->group(function () {
            Route::get('/', [ApiIntegrationController::class, 'index'])
                ->middleware('permission:view integrations');

            Route::post('/', [ApiIntegrationController::class, 'store'])
                ->middleware('permission:create integrations');

            Route::get('{api}', [ApiIntegrationController::class, 'show'])
                ->middleware('permission:view integrations');

            Route::put('{api}', [ApiIntegrationController::class, 'update'])
                ->middleware('permission:edit integrations');

            Route::delete('{api}', [ApiIntegrationController::class, 'destroy'])
                ->middleware('permission:delete integrations');

            Route::post('{api}/test', [ApiIntegrationController::class, 'test'])
                ->middleware('permission:test integrations');
        });
    });

    // ── Conversations ─────────────────────────────────────────────────────────
    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index'])
            ->middleware('permission:view conversations');

        Route::get('export', [ConversationController::class, 'export'])
            ->middleware('permission:export conversations');

        Route::get('statistics', [ConversationController::class, 'statistics'])
            ->middleware('permission:view analytics');

        Route::get('{conversation}', [ConversationController::class, 'show'])
            ->middleware('permission:view conversation-details');

        Route::get('{conversation}/messages', [ConversationController::class, 'messages'])
            ->middleware('permission:view conversation-details');

        Route::post('{conversation}/handoff', [ConversationController::class, 'handoff'])
            ->middleware('permission:handoff conversations');

        Route::post('{conversation}/end', [ConversationController::class, 'end'])
            ->middleware('permission:handoff conversations');

        Route::delete('{conversation}', [ConversationController::class, 'destroy'])
            ->middleware('permission:delete conversations');
    });

    // ── Inbox ─────────────────────────────────────────────────────────────────
    Route::prefix('inbox')->group(function () {
        Route::get('conversations', [InboxController::class, 'index'])
            ->middleware('permission:view conversations');

        Route::get('conversations/{conversation}', [InboxController::class, 'show'])
            ->middleware('permission:view conversation-details');

        Route::post('conversations/{conversation}/media', [InboxController::class, 'sendMedia'])
            ->middleware('permission:handoff conversations');

        Route::post('conversations/{conversation}/messages', [InboxController::class, 'sendMessage'])
            ->middleware('permission:handoff conversations');

        Route::post('conversations/{conversation}/read', [InboxController::class, 'markRead'])
            ->middleware('permission:view conversations');

        Route::post('conversations/{conversation}/typing', [InboxController::class, 'typing'])
            ->middleware('permission:handoff conversations');

        Route::get('accounts', [InboxController::class, 'accounts'])
            ->middleware('permission:view whatsapp-accounts');
    });

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::prefix('analytics')->group(function () {
        Route::get('overview', [AnalyticsController::class, 'overview'])
            ->middleware('permission:view analytics');

        Route::get('export', [AnalyticsController::class, 'export'])
            ->middleware('permission:export analytics');
    });

    // ── Message Templates ─────────────────────────────────────────────────────
    Route::get('message-templates', [MessageTemplateController::class, 'index'])
        ->middleware('permission:view templates');

    Route::post('message-templates', [MessageTemplateController::class, 'store'])
        ->middleware('permission:create templates');

    Route::get('message-templates/{messageTemplate}', [MessageTemplateController::class, 'show'])
        ->middleware('permission:view templates');

    Route::put('message-templates/{messageTemplate}', [MessageTemplateController::class, 'update'])
        ->middleware('permission:edit templates');

    Route::delete('message-templates/{messageTemplate}', [MessageTemplateController::class, 'destroy'])
        ->middleware('permission:delete templates');

    // ── Global / Built-in (read-only, any authenticated tenant user) ──────────
    Route::get('built-in-functions', [CustomFunctionController::class, 'builtInFunctions'])
        ->middleware('permission:view functions');

    Route::get('function-templates', [CustomFunctionController::class, 'templates'])
        ->middleware('permission:view functions');
});