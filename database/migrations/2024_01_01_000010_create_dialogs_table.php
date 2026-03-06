<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialogs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique(); // matches Vue node.id
            $table->foreignId('flow_version_id')->constrained('flow_versions')->cascadeOnDelete();
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
            // For input nodes: variable name to store the user's reply
            $table->string('input_variable')->nullable();
            $table->timestamps();
            $table->index(['flow_version_id', 'kind']);
            $table->index('is_entry_point');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialogs');
    }
};
