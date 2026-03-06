<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->json('variables');
            // Points to the last dialog the user was on (no FK — dialogs can be versioned)
            $table->unsignedBigInteger('last_dialog_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('conversation_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_contexts');
    }
};
