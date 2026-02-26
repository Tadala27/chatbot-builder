<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add source tracking to conversation_variables so the system can
     * distinguish between:
     *   - 'response'  → auto-saved when a user selects a list row / button
     *                   that has saveResponse=true. Keyed by the variable name
     *                   the builder configured, tagged with which node produced it.
     *   - 'variable'  → explicitly set via a "Set Variable" action or from an
     *                   API result, a function result, or a user text input.
     *
     * This lets the frontend show two separate pickers in the condition builder:
     * "Variable / Property" vs "Saved Selection (from node)".
     */
    public function up(): void
    {
        Schema::table('conversation_variables', function (Blueprint $table) {
            // Where this value came from
            $table->enum('source', ['variable', 'response'])
                  ->default('variable')
                  ->after('value');

            // UUID of the FlowNode that produced a 'response' value.
            // Null for 'variable' type entries.
            $table->string('source_node_uuid')->nullable()->after('source');

            // Human-readable label of the node (cached so we don't need a JOIN
            // every time we build the condition-picker list in the frontend).
            $table->string('source_node_label')->nullable()->after('source_node_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_variables', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_node_uuid', 'source_node_label']);
        });
    }
};
