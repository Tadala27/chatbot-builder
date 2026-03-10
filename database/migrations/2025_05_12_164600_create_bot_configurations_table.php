<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->unique()->constrained()->cascadeOnDelete();

            // ── Entry point ───────────────────────────────────────────────────
            // The dialog the bot jumps to when a fresh conversation starts.
            // If null the executor falls back to the is_entry_point dialog on the version.
            $table->unsignedBigInteger('starting_dialog_id')->nullable();

            // ── Fallback / invalid-input handling ─────────────────────────────
            // Sent when the user's free-text reply doesn't match any expected value.
            $table->unsignedBigInteger('invalid_input_dialog_id')->nullable();

            // ── Retry / re-engagement ─────────────────────────────────────────
            // When enabled, if the conversation has been silent for retry_after_minutes
            // and hasn't hit max_retry_attempts, the bot re-sends retry_dialog_id.
            $table->unsignedBigInteger('retry_dialog_id')->nullable();
            $table->boolean('retry_enabled')->default(false);
            $table->unsignedSmallInteger('retry_after_minutes')->nullable();
            $table->unsignedTinyInteger('max_retry_attempts')->default(1);

            // ── Global keyword triggers ───────────────────────────────────────
            // handover_keywords: any message matching these words triggers agent handoff.
            // home_keywords:     restart from starting_dialog_id (e.g. "menu", "home").
            // back_keywords:     navigate to the previous dialog (e.g. "back", "0").
            $table->json('handover_keywords')->nullable();
            $table->json('home_keywords')->nullable();
            $table->json('back_keywords')->nullable();

            // ── Human handover ────────────────────────────────────────────────
            $table->boolean('handover_enabled')->default(false);
            $table->unsignedBigInteger('handover_dialog_id_in_hours')->nullable();
            $table->unsignedBigInteger('handover_dialog_id_off_hours')->nullable();
            $table->unsignedSmallInteger('session_timeout_minutes')->default(1440); // 24 h
            $table->json('operating_hours')->nullable();
            $table->timestamps();
            $table->foreign('starting_dialog_id')
                ->references('id')->on('dialogs')->nullOnDelete();
            $table->foreign('invalid_input_dialog_id')
                ->references('id')->on('dialogs')->nullOnDelete();
            $table->foreign('retry_dialog_id')
                ->references('id')->on('dialogs')->nullOnDelete();
            $table->foreign('handover_dialog_id_in_hours')
                ->references('id')->on('dialogs')->nullOnDelete();
            $table->foreign('handover_dialog_id_off_hours')
                ->references('id')->on('dialogs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_configurations');
    }
};
