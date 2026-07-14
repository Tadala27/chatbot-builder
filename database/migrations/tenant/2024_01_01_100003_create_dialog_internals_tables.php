<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── 1. Dialogs ─────────────────────────────────────────────────────
        Schema::create('dialogs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('uuid', 36)->unique();               // matches Vue node.id
            $table->foreignUuid('bot_version_id')
                  ->constrained('bot_versions')
                  ->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('kind', 30)->default('message');

            // Full node payload (text, buttons, sections, mediaUrl, etc.)
            $table->json('config')->nullable();

            // Canvas position
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);

            // Meta flags (derived from config but stored for fast querying)
            $table->boolean('is_entry_point')->default(false);
            $table->boolean('is_terminal')->default(false);

            // For input nodes: conversation variable to store the user's reply
            $table->string('input_variable')->nullable();

            $table->timestamps();

            $table->index(['bot_version_id', 'kind']);
            $table->index('is_entry_point');
        });

        // Generated column so we can query config->>'$.id' efficiently.
        DB::statement("
            ALTER TABLE dialogs
            ADD COLUMN config_id VARCHAR(255)
            GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(config, '$.id'))) STORED
        ");
        DB::statement('
            CREATE INDEX idx_dialogs_version_config_id
            ON dialogs(bot_version_id, config_id)
        ');
        // ── 3. Dialog options (buttons / list rows) ────────────────────────
        Schema::create('dialog_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dialog_id')->constrained('dialogs')->cascadeOnDelete();

            // Frontend UUID — unique per dialog (not globally unique, because the same
            // external_id is preserved when cloning a bot version).
            $table->string('external_id');
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('section_title')->nullable();        // list nodes only
            $table->unsignedSmallInteger('section_order')->nullable();
            $table->unsignedSmallInteger('option_order')->default(0);
            $table->boolean('save_response')->default(false);
            $table->timestamps();

            $table->unique(['dialog_id', 'external_id'], 'dialog_options_dialog_external_unique');
            $table->index(['dialog_id', 'option_order']);
        });

        // ── 4. Actions ─────────────────────────────────────────────────────
        // action_type values:
        //   send_message | set_variable | call_api | call_function |
        //   condition_branch | jump_to_dialog | handover_to_agent |
        //   send_webhook | end_conversation
        Schema::create('actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dialog_id')->constrained('dialogs')->cascadeOnDelete();
            $table->foreignUuid('then_action_id')
                  ->nullable()
                  ->constrained('actions')
                  ->nullOnDelete();
            $table->string('action_type', 50);
            $table->unsignedSmallInteger('action_order')->default(0);
            $table->json('config')->nullable();                 // action-specific payload
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['dialog_id', 'is_active', 'action_order'],
                'actions_dialog_active_order_idx'
            );
        });

        // ── 5. Action conditions ───────────────────────────────────────────
        // condition_type values:
        //   variable | saved_response | api_response
        Schema::create('action_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('action_id')->constrained('actions')->cascadeOnDelete();

            $table->string('condition_type', 30);
            $table->string('condition_operator', 30)->nullable();
            // equals | not_equals | greater_than | less_than | contains |
            // starts_with | ends_with | is_empty | is_not_empty

            // ── variable ───────────────────────────────────────────────────
            $table->string('variable_key')->nullable();
            $table->text('condition_value')->nullable();

            // ── saved_response ─────────────────────────────────────────────
            $table->foreignUuid('option_id')
                  ->nullable()
                  ->constrained('dialog_options')
                  ->nullOnDelete();

            // ── api_response ───────────────────────────────────────────────
            $table->string('response_field', 20)->nullable();  // status | body | header
            $table->string('response_path')->nullable();        // dot-path e.g. "data.success"
            // condition_value reused for expected response value

            $table->unsignedSmallInteger('condition_order')->default(0);
            $table->timestamps();

            $table->index(['action_id', 'condition_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_conditions');
        Schema::dropIfExists('actions');
        Schema::dropIfExists('dialog_options');

        Schema::table('bot_versions', function (Blueprint $table) {
            $table->dropForeign(['start_node_id']);
        });

        Schema::dropIfExists('dialogs');
    }
};
