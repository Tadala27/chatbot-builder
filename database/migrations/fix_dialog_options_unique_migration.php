<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The external_id on dialog_options is a frontend UUID (row.id / btn.id).
     * It only needs to be unique WITHIN a dialog, not globally across all dialogs.
     * When cloning a version, the same external_ids are copied to new dialogs —
     * so a global unique constraint breaks on every version branch.
     */
    public function up(): void
    {
        Schema::table('dialog_options', function (Blueprint $table) {
            // Drop the global unique index on external_id
            $table->dropUnique(['external_id']);

            // Replace with composite: unique per dialog
            $table->unique(['dialog_id', 'external_id'], 'dialog_options_dialog_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dialog_options', function (Blueprint $table) {
            $table->dropUnique('dialog_options_dialog_external_unique');
            $table->unique('external_id');
        });
    }
};
