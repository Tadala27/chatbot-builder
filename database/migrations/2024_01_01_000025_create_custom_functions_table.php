<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->enum('function_type', ['javascript', 'webhook', 'built_in'])->default('javascript');
            $table->text('code')->nullable();
            $table->json('parameters')->nullable();
            $table->string('return_type', 50)->nullable();
            $table->integer('timeout_seconds')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['bot_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_functions');
    }
};
