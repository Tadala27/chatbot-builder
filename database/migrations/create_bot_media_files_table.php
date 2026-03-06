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

            // Tenant scoping — every record belongs to a tenant
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // The user who uploaded it (within that tenant)
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The bot this media belongs to
            $table->foreignId('bot_id')
                ->constrained('bots')
                ->cascadeOnDelete();

            // Original name the user uploaded (used to auto-populate the "Document Filename" field)
            $table->string('original_filename');

            // UUID-based filename stored on disk (collision-safe)
            $table->string('stored_filename');

            // Storage disk (local, s3, etc.) — easy to migrate later
            $table->string('disk')->default('public');

            // Full path relative to the disk root  e.g. "bot-media/42/uuid.jpg"
            $table->string('path');

            // The public URL returned to the frontend / sent to WhatsApp
            $table->string('url');

            // WhatsApp media category: image | video | audio | document
            $table->string('media_type', 20);

            // MIME type e.g. image/jpeg
            $table->string('mime_type', 100)->nullable();

            // File size in bytes
            $table->unsignedBigInteger('size')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['tenant_id', 'bot_id', 'media_type']);
            $table->index(['tenant_id', 'bot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_media_files');
    }
};
