<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Central auth tables.
 *
 * These live in the LANDLORD database only.
 * Central users are super-admins and billing/ops staff who manage tenants.
 * Tenant staff have their own users table in each tenant's database.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Central users (landlord admins / ops staff) ───────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->id();
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

        // ── Password resets ───────────────────────────────────────────────
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // ── Sanctum / personal access tokens ─────────────────────────────
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ── Failed queue jobs ─────────────────────────────────────────────
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // ── 2. Global variables (tenant-wide, all bots) ───────────────────
        Schema::create('global_variables', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->enum('data_type', ['string', 'number', 'boolean', 'json', 'date'])
                  ->default('string');
            $table->boolean('is_encrypted')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('key');
        });

        Schema::create('built_in_functions', function (Blueprint $table) {
            $table->id();
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

        Schema::create('whatsapp_phone_index', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number_id')->unique();
            $table->string('tenant_id');
            $table->string('verify_token')->nullable();
            $table->timestamps();
 
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
        });
        Schema::create('connector_key_index', function (Blueprint $table) {
            $table->id();
            $table->string('connector_api_key', 64)->unique();
            $table->string('tenant_id');
            $table->timestamps();

            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('connector_key_index');
        Schema::dropIfExists('whatsapp_phone_index');
        Schema::dropIfExists('built_in_functions');
        Schema::dropIfExists('global_variables');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};