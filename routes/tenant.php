<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiIntegrationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotBuilderController;
use App\Http\Controllers\Api\BotConfigurationController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\BotDialogController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomFunctionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\VariableController;
use App\Http\Controllers\Api\WhatsAppAccountController;
use App\Http\Controllers\Api\WhatsappRegistrationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware(['auth:tenant']);
// =============================================================================
// PUBLIC — No authentication required
// =============================================================================

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
        Route::get('me', [AuthController::class, 'me']);
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

    // ── WhatsApp registration ─────────────────────────────────────────────────
    Route::prefix('whatsapp')->group(function () {
        Route::prefix('register')->group(function () {
            Route::post('add-number', [WhatsappRegistrationController::class, 'addNumber']);
            Route::post('request-code', [WhatsappRegistrationController::class, 'requestCode']);
            Route::post('verify-code', [WhatsappRegistrationController::class, 'verifyCode']);
            Route::post('complete', [WhatsappRegistrationController::class, 'completeRegistration']);
        });
        Route::get('accounts/{account}/health', [WhatsappRegistrationController::class, 'health']);
        Route::post('accounts/{account}/sync', [WhatsappRegistrationController::class, 'sync']);
    });

    // ── WhatsApp accounts ─────────────────────────────────────────────────────
    Route::prefix('whatsapp-accounts')->group(function () {
        Route::get('/', [WhatsAppAccountController::class, 'index'])
            ->middleware('permission:view whatsapp-accounts');
        Route::post('/embedded-signup/callback', [WhatsAppAccountController::class, 'embeddedSignupCallback']);
        Route::get('connector', [WhatsAppAccountController::class, 'connectorAccount'])
            ->middleware('permission:view whatsapp-accounts');
        Route::post('connect-connector', [WhatsAppAccountController::class, 'connectConnector'])
            ->middleware('permission:connect whatsapp-accounts');
        Route::get('signup-url', [WhatsAppAccountController::class, 'getSignupUrl'])
            ->middleware('permission:connect whatsapp-accounts');
        Route::post('callback', [WhatsAppAccountController::class, 'handleCallback'])
            ->middleware('permission:connect whatsapp-accounts');
        Route::get('{account}', [WhatsAppAccountController::class, 'show'])
            ->middleware('permission:view whatsapp-accounts');
        Route::put('{account}', [WhatsAppAccountController::class, 'update'])
            ->middleware('permission:manage whatsapp-accounts');
        Route::post('/{account}/choose-mode', [WhatsAppAccountController::class, 'chooseMode']);
        Route::get('/{account}/connector-info', [WhatsAppAccountController::class, 'connectorInfo']);
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
        // Media library
        Route::get('media', [MediaController::class, 'index'])
            ->middleware('permission:view bots');
        Route::post('media', [MediaController::class, 'upload'])
            ->middleware('permission:edit bots');

        // Bot settings / configuration
        Route::get('settings', [BotConfigurationController::class, 'show']);
        Route::get('settings/dialogs', [BotConfigurationController::class, 'dialogs']);
        Route::put('settings', [BotConfigurationController::class, 'upsert']);

        // Bot dialogs (fixed-purpose, version-independent)
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

        // Bot configuration (legacy alias — kept for backwards compat)
        Route::get('configuration', [BotConfigurationController::class, 'show'])
            ->middleware('permission:view bots');
        Route::post('configuration', [BotConfigurationController::class, 'upsert'])
            ->middleware('permission:edit bots');

        // Bot analytics
        Route::prefix('analytics')->group(function () {
            Route::get('/', [AnalyticsController::class, 'bot'])
                ->middleware('permission:view analytics');
            Route::get('paths', [AnalyticsController::class, 'popularPathsEndpoint'])
                ->middleware('permission:view detailed-analytics');
            Route::get('drop-offs', [AnalyticsController::class, 'dropOffPointsEndpoint'])
                ->middleware('permission:view detailed-analytics');
        });

        // Bot builder
        Route::get('/', [BotBuilderController::class, 'show']);
        Route::post('/save', [BotBuilderController::class, 'autoSave']);
        Route::post('/publish', [BotBuilderController::class, 'publish']);
        Route::get('/versions', [BotBuilderController::class, 'getVersions']);
        Route::get('/versions/{version}', [BotBuilderController::class, 'getVersion']);
        Route::post('/versions', [BotBuilderController::class, 'createVersion']);
        Route::get('/variables', [BotBuilderController::class, 'getVariables']);
        Route::get('/functions', [BotBuilderController::class, 'getFunctions']);

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

        // Custom functions
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

        // API integrations
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

    // ── Media file operations (not bot-scoped — operate on individual files) ──
    Route::delete('media/{media}', [MediaController::class, 'destroy'])
        ->middleware('permission:edit bots')
        ->name('tenant.media.delete');

    Route::get('media/{media}/serve', [MediaController::class, 'serve'])
        ->middleware('permission:view bots')
        ->name('tenant.media.serve');

    Route::get('media/{media}/serve/stream', [MediaController::class, 'serveStream'])
        ->middleware('permission:view bots')
        ->name('tenant.media.serve.stream');

    // Upload a stored BotMediaFile to Meta's media API → returns a media_id.
    Route::post('media/{media}/meta-upload', [MediaController::class, 'uploadToMeta'])
        ->middleware('permission:edit bots')
        ->name('tenant.media.meta-upload');

    // ── Inbound message media (proxy from Meta CDN to agent UI) ──────────────
    // These routes use {message} parameter, NOT {media}
    Route::get('messages/{message}/media', [MediaController::class, 'stream'])
        ->middleware('permission:view conversation-details')
        ->name('tenant.message.media.stream');  // ← This is the correct route

    Route::get('messages/{message}/media/info', [MediaController::class, 'info'])
        ->middleware('permission:view conversation-details')
        ->name('tenant.message.media.info');
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
        Route::post('{conversation}/messages', [ConversationController::class, 'store']);
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

    // ── Global / Built-in (any authenticated tenant user) ────────────────────
    Route::get('built-in-functions', [CustomFunctionController::class, 'builtInFunctions'])
        ->middleware('permission:view functions');
    Route::get('function-templates', [CustomFunctionController::class, 'templates'])
        ->middleware('permission:view functions');
});