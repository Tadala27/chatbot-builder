<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Channels, bots, flows — runs inside EACH TENANT's database.
 *
 * Tables (in dependency order):
 *   1. whatsapp_accounts          — connected WA business numbers, with
 *                                   built-in support for two modes:
 *                                     - managed_bot (default): messages are
 *                                       routed into this tenant's bots/flows,
 *                                       same as before.
 *                                     - connector: messages are NOT processed
 *                                       by the flow engine. They're minimally
 *                                       logged to connector_message_logs and
 *                                       forwarded as a raw payload to
 *                                       webhook_url. Outbound replies come
 *                                       from the tenant's own external system
 *                                       via POST /api/connector/{tenant_slug}/messages,
 *                                       authenticated by connector_api_key.
 *   2. connector_message_logs     — minimal audit trail for connector-mode
 *                                   traffic only (not used by managed_bot
 *                                   accounts)
 *   3. facebook_business_accounts — FB OAuth tokens for WA embedded signup
 *   4. bots                       — bot instances owned by this tenant
 *   5. bot_media_files            — media library per bot
 *   6. flows                      — named conversation flows (no version FK yet)
 *   7. flow_versions              — versioned snapshots of a flow
 *   8. flows (FK patch)           — adds current_published_version_id FK now that
 *                                   flow_versions exists (avoids circular dep)
 *   9. bot_dialogs                — reusable system-level dialogs per bot
 *                                   (welcome, invalid-input, handover, etc.)
 *  10. bot_configurations         — one-row config per bot referencing bot_dialogs
 */
return new class extends Migration {
    public function up(): void
    {
        // ── 1. WhatsApp accounts ──────────────────────────────────────────
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('waba_id')->unique();
            $table->string('phone_number_id')->unique();
            $table->string('phone_number', 20);
            $table->string('display_phone_number', 20)->nullable();
            $table->string('verified_name')->nullable();
            $table->enum('quality_rating', ['GREEN', 'YELLOW', 'RED', 'UNKNOWN'])->default('UNKNOWN');
            $table->enum('messaging_limit', ['TIER_1K', 'TIER_10K', 'TIER_100K', 'TIER_UNLIMITED'])
                  ->default('TIER_1K');
            $table->text('access_token')->nullable();
            $table->string('webhook_verify_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('mode', ['managed_bot', 'connector'])->default('managed_bot');
            $table->string('webhook_url')->nullable();
            $table->string('connector_api_key', 64)->unique()->nullable();
            $table->timestamp('connector_api_key_rotated_at')->nullable();
            $table->unsignedInteger('webhook_failure_count')->default(0);
            $table->timestamp('webhook_last_failed_at')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('mode');
            $table->index('connector_api_key');
        });

        Schema::create('connector_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('whatsapp_user_phone', 20);
            $table->string('whatsapp_message_id')->nullable();
            $table->enum('status', ['received', 'forwarded', 'forward_failed', 'sent', 'send_failed'])
                  ->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['whatsapp_account_id', 'created_at']);
            $table->index('whatsapp_user_phone');
        });

        // ── 3. Facebook Business accounts ─────────────────────────────────
        Schema::create('facebook_business_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('fb_business_id');
            $table->string('fb_user_id')->nullable();
            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->timestamps();
        });

        // ── 4. Bots ───────────────────────────────────────────────────────
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();             // owner
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete(); // linked WA number
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('fallback_message')->nullable();
            $table->string('welcome_message')->nullable();
            $table->string('default_language', 10)->default('en');
            $table->json('supported_languages')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index('is_active');
        });

        // ── 5. Bot media files ────────────────────────────────────────────
        Schema::create('bot_media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('url');
            $table->enum('media_type', ['image', 'video', 'audio', 'document']);
            $table->string('mime_type');
            $table->unsignedBigInteger('size'); // bytes
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bot_id', 'media_type']);
        });

        // ── 6. Flows (no version FK yet — added below after flow_versions) ─
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedBigInteger('current_published_version_id')->nullable(); // FK added below
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bot_id', 'slug']);
            $table->index(['bot_id', 'status']);
        });

        // ── 7. Flow versions ─────────────────────────────────────────────
        Schema::create('flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->enum('status', ['draft', 'published', 'locked'])->default('draft');
            $table->unsignedBigInteger('start_node_id')->nullable(); // FK to dialogs — added later
            $table->text('changelog')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['flow_id', 'version_number']);
            $table->index(['flow_id', 'status']);
        });

        // ── 8. Patch flows with the circular FK now that flow_versions exists ─
        Schema::table('flows', function (Blueprint $table) {
            $table->foreign('current_published_version_id')
                  ->references('id')
                  ->on('flow_versions')
                  ->nullOnDelete();
        });

        // ── 9. Bot dialogs (system-level: welcome, invalid-input, handover) ─
        Schema::create('bot_dialogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('purpose')->index(); // e.g. 'welcome', 'invalid_input', 'handover_in_hours'
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind')->default('message');
            $table->json('config')->nullable();
            $table->boolean('is_entry_point')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bot_id', 'purpose']);
        });

        // ── 10. Bot configuration (one row per bot) ───────────────────────
        Schema::create('bot_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->unique()->constrained()->cascadeOnDelete();

            // System dialog references
            $table->foreignId('starting_dialog_id')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignId('invalid_input_dialog_id')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignId('invalid_attempts_dialog_id')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignId('retry_dialog_id')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignId('handover_dialog_id_in_hours')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignId('handover_dialog_id_off_hours')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignId('welcome_dialog_id')
                  ->nullable()->constrained('bot_dialogs')->nullOnDelete();

            // Text fields
            $table->string('invalid_input_message')->nullable();
            $table->string('handover_unavailable_message')->nullable();

            // Attempt / retry / timeout limits
            $table->unsignedTinyInteger('max_invalid_attempts')->default(3);
            $table->boolean('retry_enabled')->default(false);
            $table->unsignedSmallInteger('retry_after_minutes')->nullable();
            $table->unsignedTinyInteger('max_retry_attempts')->default(1);
            $table->unsignedSmallInteger('session_timeout_minutes')->default(1440);
            $table->unsignedSmallInteger('auto_resolve_after_minutes')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(10);

            // Keyword triggers (JSON arrays)
            $table->json('handover_keywords')->nullable();
            $table->json('home_keywords')->nullable();
            $table->json('back_keywords')->nullable();
            $table->json('opt_out_keywords')->nullable();
            $table->json('opt_in_keywords')->nullable();

            // Feature flags
            $table->boolean('handover_enabled')->default(false);
            $table->boolean('welcome_message_enabled')->default(false);

            // Scheduling
            $table->string('timezone')->default('UTC');
            $table->json('operating_hours')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_configurations');
        Schema::dropIfExists('bot_dialogs');

        Schema::table('flows', function (Blueprint $table) {
            $table->dropForeign(['current_published_version_id']);
        });

        Schema::dropIfExists('flow_versions');
        Schema::dropIfExists('flows');
        Schema::dropIfExists('bot_media_files');
        Schema::dropIfExists('bots');
        Schema::dropIfExists('facebook_business_accounts');
        Schema::dropIfExists('connector_message_logs');
        Schema::dropIfExists('whatsapp_accounts');
    }
};