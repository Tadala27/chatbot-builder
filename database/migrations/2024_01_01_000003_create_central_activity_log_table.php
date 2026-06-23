<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity log — CENTRAL copy.
 *
 * Tracks actions performed by central/ops users on the landlord database
 * (tenant provisioning, plan changes, admin logins, etc.).
 *
 * A separate copy of this migration lives in database/migrations/tenant/
 * so that each tenant database also gets its own activity log for
 * per-tenant audit trails (bot changes, flow publishes, payroll actions, etc.).
 *
 * The table name and connection are both driven by spatie/laravel-activitylog config
 * so that local overrides (config/activitylog.php) are respected automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
              ->create(config('activitylog.table_name'), function (Blueprint $table) {
                  $table->bigIncrements('id');
                  $table->string('log_name')->nullable();
                  $table->text('description');
                  $table->nullableMorphs('subject', 'subject');
                  $table->string('event')->nullable();
                  $table->nullableMorphs('causer', 'causer');
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
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
              ->dropIfExists(config('activitylog.table_name'));
    }
};
