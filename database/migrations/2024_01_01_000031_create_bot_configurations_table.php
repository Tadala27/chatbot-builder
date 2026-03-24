<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete(); // ADD THIS

            // Dialog foreign keys
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
            $table->string('handover_unavailable_message')->nullable(); // ADD THIS

            // Numeric fields
            $table->unsignedTinyInteger('max_invalid_attempts')->default(3); // REMOVED ->change()
            $table->boolean('retry_enabled')->default(false);
            $table->unsignedSmallInteger('retry_after_minutes')->nullable();
            $table->unsignedTinyInteger('max_retry_attempts')->default(1);
            $table->unsignedSmallInteger('session_timeout_minutes')->default(1440);
            $table->unsignedSmallInteger('auto_resolve_after_minutes')->nullable(); // ADD THIS
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(10);

            // Keyword fields (JSON)
            $table->json('handover_keywords')->nullable();
            $table->json('home_keywords')->nullable();
            $table->json('back_keywords')->nullable();
            $table->json('opt_out_keywords')->nullable(); // ADD THIS
            $table->json('opt_in_keywords')->nullable(); // ADD THIS

            // Boolean fields
            $table->boolean('handover_enabled')->default(false);
            $table->boolean('welcome_message_enabled')->default(false);

            // Other fields
            $table->string('timezone')->default('UTC');
            $table->json('operating_hours')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_configurations');
    }
};