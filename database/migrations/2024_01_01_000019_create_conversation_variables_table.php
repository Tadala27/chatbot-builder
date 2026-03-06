<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Optional reference to the schema definition; null for ad-hoc variables
            $table->foreignId('custom_variable_id')->nullable()->constrained('custom_variables')->nullOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_variables');
    }
};
