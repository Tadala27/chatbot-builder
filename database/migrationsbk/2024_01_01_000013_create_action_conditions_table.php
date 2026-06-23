<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | ACTION_CONDITIONS
    |--------------------------------------------------------------------------
    | Belongs to an action (condition_branch or api_response_handler).
    |
    | condition_type:
    |   variable       — check a conversation variable e.g. {{district}} == "Blantyre"
    |   saved_response — check which button/row the user tapped on a previous node
    |   api_response   — check status code or body field of the last API call
    */
    public function up(): void
    {
        Schema::create('action_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->string('condition_type', 30); // variable | saved_response | api_response
            $table->string('condition_operator', 30)->nullable();
            // equals | not_equals | greater_than | less_than | contains | starts_with |
            // ends_with | is_empty | is_not_empty

            // ── variable ──────────────────────────────────────────────────────
            $table->string('variable_key')->nullable();   // the {{key}} name
            $table->text('condition_value')->nullable();  // expected value

            // ── saved_response ─────────────────────────────────────────────────
            $table->foreignId('option_id')
                  ->nullable()
                  ->constrained('dialog_options')
                  ->nullOnDelete();

            // ── api_response ───────────────────────────────────────────────────
            $table->string('response_field', 20)->nullable(); // status | body | header
            $table->string('response_path')->nullable();      // dot path e.g. "data.success"
            // condition_value reused for expected response value

            $table->unsignedSmallInteger('condition_order')->default(0);
            $table->timestamps();
            $table->index(['action_id', 'condition_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_conditions');
    }
};
