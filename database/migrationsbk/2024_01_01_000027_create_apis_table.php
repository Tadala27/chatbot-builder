<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('method', 10);
            $table->text('url');
            $table->string('content_type')->nullable();
            $table->json('headers')->nullable();
            $table->text('request_body')->nullable();
            $table->json('form_data')->nullable();
            $table->json('url_encoded_fields')->nullable();
            $table->json('body_parameters')->nullable();
            $table->json('header_parameters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['bot_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apis');
    }
};
