<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tenant auth tables — runs inside EACH TENANT's database.
 *
 * Tenant staff (bot builders, etc.) authenticate
 * against this users table, scoped entirely to their own tenant database.
 * They never touch the central (landlord) users table.
 *
 * Also includes:
 * - password_reset_tokens  — for tenant-staff password resets
 * - personal_access_tokens — if tenant API access is needed (e.g. webhooks)
 * - activity_log           — per-tenant audit trail (bot changes, payroll actions, etc.)
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Tenant staff users ────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_super_admin')->default(false); // "tenant admin" flag
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
            $table->uuid('id')->primary();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ── Per-tenant activity log ───────────────────────────────────────
        // Mirrors the central activity log but scoped to this tenant's DB.
        // Uses the same spatie/laravel-activitylog config for table name.
        Schema::create(config('activitylog.table_name', 'activity_log'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index('log_name');
            $table->index('subject_type');
            $table->index('subject_id');
            $table->index('causer_type');
            $table->index('causer_id');
            $table->index('created_at');
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
        Schema::dropIfExists(config('activitylog.table_name', 'activity_log'));
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
