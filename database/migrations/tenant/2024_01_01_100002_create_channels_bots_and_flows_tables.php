<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('facebook_business_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('fb_business_id')->index();
            $table->string('fb_user_id')->nullable();
            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->string('waba_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('onboarding_method', ['embedded_signup', 'registered_number'])
                ->default('embedded_signup');
            $table->foreignUuid('fb_business_account_id')
                ->nullable()
                ->constrained('facebook_business_accounts')
                ->nullOnDelete();
            $table->string('waba_id');
            $table->string('phone_number_id')->nullable();
            $table->string('phone_number', 20);
            $table->string('display_phone_number', 20)->nullable();
            $table->string('verified_name')->nullable();
            $table->text('access_token')->nullable();
            $table->enum('quality_rating', ['GREEN', 'YELLOW', 'RED', 'UNKNOWN'])
                ->default('UNKNOWN');
            $table->enum('messaging_limit', [
                'TIER_1K', 'TIER_10K', 'TIER_100K', 'TIER_UNLIMITED',
            ])->default('TIER_1K');
            $table->enum('onboarding_status', [
                'pending',
                'code_requested',
                'verified',
                'active',
                'failed',
                'suspended',
            ])->default('pending');

            $table->enum('verification_method', ['sms', 'voice'])->nullable();
            $table->string('phone_number_pin', 6)->nullable();
            $table->enum('mode', ['managed_bot', 'connector'])->default('managed_bot');

            $table->string('webhook_url')->nullable();
            $table->string('webhook_verify_token')->nullable();
            $table->string('connector_api_key', 64)->unique()->nullable();
            $table->timestamp('connector_api_key_rotated_at')->nullable();

            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('webhook_failure_count')->default(0);
            $table->timestamp('webhook_last_failed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('registered_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['waba_id', 'phone_number_id'], 'wa_accounts_waba_phone_unique');
            $table->index('is_active');
            $table->index('mode');
            $table->index('onboarding_method');
            $table->index('onboarding_status');
            $table->index('connector_api_key');
        });

        Schema::create('connector_message_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('whatsapp_user_phone', 20);
            $table->string('whatsapp_message_id')->nullable();
            $table->enum('status', [
                'received', 'forwarded', 'forward_failed', 'sent', 'send_failed',
            ])->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['whatsapp_account_id', 'created_at']);
            $table->index('whatsapp_user_phone');
        });

        Schema::create('bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignUuid('whatsapp_account_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('default_language', 10)->default('en');
            $table->json('supported_languages')->nullable();
            $table->json('settings')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index('is_active');
            $table->index('whatsapp_account_id');
        });

        Schema::create('bot_media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('url');
            $table->enum('media_type', ['image', 'video', 'audio', 'document']);
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bot_id', 'media_type']);
        });

        Schema::create('bot_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->enum('status', ['draft', 'published', 'locked'])->default('draft');
            $table->text('changelog')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bot_id', 'version_number']);
            $table->index(['bot_id', 'status', 'published_at'], 'bot_versions_lookup_idx');
            $table->index(['bot_id', 'status']);
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->foreignUuid('current_published_version_id')->nullable()->constrained('bot_versions')->nullOnDelete();
        });

        Schema::create('bot_dialogs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->string('purpose')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind')->default('message');
            $table->json('config')->nullable();
            $table->boolean('is_entry_point')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bot_id', 'purpose']);
        });

        Schema::create('bot_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bot_id')->unique()->constrained()->cascadeOnDelete();

            $table->foreignUuid('starting_dialog_id')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignUuid('welcome_dialog_id')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignUuid('invalid_input_dialog_id')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignUuid('invalid_attempts_dialog_id')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignUuid('retry_dialog_id')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignUuid('handover_dialog_id_in_hours')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();
            $table->foreignUuid('handover_dialog_id_off_hours')
                ->nullable()->constrained('bot_dialogs')->nullOnDelete();

            $table->string('invalid_input_message')->nullable();
            $table->string('handover_unavailable_message')->nullable();

            $table->unsignedTinyInteger('max_invalid_attempts')->default(3);
            $table->boolean('retry_enabled')->default(false);
            $table->unsignedSmallInteger('retry_after_minutes')->nullable();
            $table->unsignedTinyInteger('max_retry_attempts')->default(1);
            $table->unsignedSmallInteger('session_timeout_minutes')->default(1440);
            $table->unsignedSmallInteger('auto_resolve_after_minutes')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(10);

            $table->json('handover_keywords')->nullable();
            $table->json('home_keywords')->nullable();
            $table->json('back_keywords')->nullable();
            $table->json('opt_out_keywords')->nullable();
            $table->json('opt_in_keywords')->nullable();

            $table->boolean('handover_enabled')->default(false);
            $table->boolean('welcome_message_enabled')->default(false);

            $table->string('timezone')->default('UTC');
            $table->json('operating_hours')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_configurations');
        Schema::dropIfExists('bot_dialogs');

        Schema::table('bots', function (Blueprint $table) {
            $table->dropForeign(['current_published_version_id']);
        });

        Schema::dropIfExists('bot_versions');
        Schema::dropIfExists('bot_media_files');
        Schema::dropIfExists('bots');
        Schema::dropIfExists('connector_message_logs');
        Schema::dropIfExists('whatsapp_accounts');
        Schema::dropIfExists('facebook_business_accounts');
    }
};