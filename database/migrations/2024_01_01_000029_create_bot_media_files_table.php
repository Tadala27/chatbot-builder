<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('url');
            $table->enum('media_type', ['image', 'video', 'audio', 'document']);
            $table->string('mime_type');
            $table->unsignedBigInteger('size');          // bytes
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bot_id', 'media_type']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_media_files');
    }
};
