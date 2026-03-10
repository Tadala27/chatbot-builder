<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | CUSTOM_VARIABLES
    |--------------------------------------------------------------------------
    | Variables defined per-bot by the bot builder.
    | Conversation variables reference these as a "schema" definition.
    */
    public function up(): void
    {
        Schema::create('custom_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key')->index();
            $table->enum('data_type', ['string', 'number', 'boolean', 'json', 'date'])->default('string');
            $table->text('default_value')->nullable();
            $table->enum('save_in', ['conversation', 'user_property', 'global'])->default('conversation');
            $table->boolean('use_in_js')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['bot_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_variables');
    }
};
