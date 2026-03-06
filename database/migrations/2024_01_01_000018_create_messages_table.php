<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_message_id')->unique()->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', [
                'text', 'image', 'video', 'audio',
                'document', 'location', 'interactive', 'template',
            ]);
            $table->json('content');
            $table->enum('status', ['sent', 'delivered', 'read', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'direction']);
            $table->index('whatsapp_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
