<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('built_in_functions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('category', ['date_time', 'string', 'logical', 'formatting', 'math', 'array']);
            $table->text('description')->nullable();
            $table->text('syntax')->nullable();
            $table->json('parameters')->nullable();
            $table->string('return_type', 50)->nullable();
            $table->json('examples')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('built_in_functions');
    }
};
