<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds:
 * - messages.metadata (JSON) — stores sender_type/sender_id/sender_name for agent messages,
 *   plus any other per-message context. Avoids adding nullable agent-specific columns
 *   to a high-volume table.
 * - messages.error_message is already in the schema per the Message model fillable array.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'metadata')) {
                $table->json('metadata')->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
