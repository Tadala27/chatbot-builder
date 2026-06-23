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
            $table->foreignId('custom_variable_id')->nullable()->constrained('custom_variables')->nullOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('value_type', 20)
                  ->default('string')
                  ->comment('string|number|boolean|json|datetime|null');
            $table->timestamps();
            $table->unique(['conversation_id', 'key'], 'conv_vars_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_variables');
    }
};