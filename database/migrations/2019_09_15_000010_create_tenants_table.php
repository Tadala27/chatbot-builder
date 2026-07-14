<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('db_schema')->unique();
            $table->enum('deployment_mode', ['shared', 'dedicated', 'self_hosted'])->default('shared');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->enum('subscription_tier', ['free', 'starter', 'professional', 'enterprise'])
                ->default('free');
            $table->timestamp('subscription_expires_at')->nullable();
            $table->integer('max_bots')->default(3);
            $table->integer('max_conversations_per_month')->default(1000);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->json('data')->nullable();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->increments('id');
            $table->string('domain', 255)->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('tenant_id');
            $table->timestamps();
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });

        Schema::create('whatsapp_phone_index', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phone_number_id')->unique();

            $table->string('tenant_id');
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');
            $table->enum('onboarding_method', ['embedded_signup', 'registered_number'])
                ->default('embedded_signup');
            $table->string('verify_token')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('connector_key_index', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('connector_api_key', 64)->unique();
            $table->string('tenant_id');
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });

        Schema::create('built_in_functions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->enum('category', ['date_time', 'string', 'logical', 'formatting', 'math', 'array']);
            $table->text('description')->nullable();
            $table->text('syntax')->nullable();
            $table->json('parameters')->nullable();
            $table->string('return_type', 50)->nullable();
            $table->json('examples')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Global variables (platform-level, available to all tenants) ───
        Schema::create('global_variables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->text('name')->nullable();
            $table->enum('data_type', ['string', 'number', 'boolean', 'json', 'date'])
                ->default('string');
            $table->boolean('is_encrypted')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ── Standard landlord infrastructure ──────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->string('avatar')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('locale')->default('en');
            $table->timestamp('last_login')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->boolean('password_reset_required')->default(false);
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('global_variables');
        Schema::dropIfExists('built_in_functions');
        Schema::dropIfExists('connector_key_index');
        Schema::dropIfExists('whatsapp_phone_index');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }
};
