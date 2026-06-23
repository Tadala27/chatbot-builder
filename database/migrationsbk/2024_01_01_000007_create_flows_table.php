<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            // FK to flow_versions added in a later migration to avoid circular dependency
            $table->unsignedBigInteger('current_published_version_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['bot_id', 'slug']);
            $table->index(['bot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
