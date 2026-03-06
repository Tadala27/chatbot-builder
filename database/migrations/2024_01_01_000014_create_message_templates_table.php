<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('category', ['utility', 'marketing', 'authentication']);
            $table->string('language', 10)->default('en');
            $table->enum('template_type', ['text', 'media', 'interactive', 'location']);
            $table->json('content');
            $table->json('variables')->nullable();
            $table->string('whatsapp_template_id')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
