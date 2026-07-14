<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
              ->create(config('activitylog.table_name'), function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
              ->dropIfExists(config('activitylog.table_name'));
    }
};
