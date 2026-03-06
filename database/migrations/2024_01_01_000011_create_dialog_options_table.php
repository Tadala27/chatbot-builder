<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialog_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dialog_id')->constrained('dialogs')->cascadeOnDelete();
            $table->string('external_id')->unique(); // WhatsApp payload id
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('section_title')->nullable(); // list nodes only
            $table->unsignedSmallInteger('section_order')->nullable();
            $table->unsignedSmallInteger('option_order')->default(0);
            $table->boolean('save_response')->default(false);
            $table->timestamps();
            $table->index(['dialog_id', 'option_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialog_options');
    }
};
