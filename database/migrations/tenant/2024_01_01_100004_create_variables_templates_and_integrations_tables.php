<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variables, templates & integrations — runs inside EACH TENANT's database.
 *
 * Tables:
 *   1. message_templates   — WhatsApp HSM templates (pending / approved / rejected)
 *   2. global_variables    — tenant-level key/value store (shared across all bots)
 *   3. custom_variables    — per-bot variable schema definitions
 *   4. custom_functions    — JS / webhook / built-in functions defined by bot builder
 *   5. built_in_functions  — platform-provided functions (date, string, math, etc.)
 *   6. apis                — reusable API call definitions per bot
 *   7. outgoing_webhooks   — outgoing webhook endpoints (tenant + optional flow scope)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Message templates ──────────────────────────────────────────
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('category', ['utility', 'marketing', 'authentication']);
            $table->string('language', 10)->default('en');
            $table->enum('template_type', ['text', 'media', 'interactive', 'location']);
            $table->json('content');
            $table->json('variables')->nullable();
            $table->string('whatsapp_template_id')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamps();

            $table->index('status');
        });


        // ── 3. Custom variables (per-bot schema definitions) ──────────────
        Schema::create('custom_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key')->index();
            $table->enum('data_type', ['string', 'number', 'boolean', 'json', 'date'])
                  ->default('string');
            $table->text('default_value')->nullable();
            $table->enum('save_in', ['conversation', 'user_property', 'global'])
                  ->default('conversation');
            $table->boolean('use_in_js')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'key']);
        });

        // ── 4. Custom functions (JS / webhook / built-in wrappers) ────────
        Schema::create('custom_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->enum('function_type', ['javascript', 'webhook', 'built_in'])
                  ->default('javascript');
            $table->text('code')->nullable();
            $table->json('parameters')->nullable();
            $table->string('return_type', 50)->nullable();
            $table->integer('timeout_seconds')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bot_id', 'slug']);
        });

        // ── 5. Built-in functions (platform catalogue, read-only at runtime) ─


        // ── 6. APIs (reusable API call definitions per bot) ───────────────
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

        // ── 7. Outgoing webhooks ──────────────────────────────────────────
        Schema::create('outgoing_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('url', 500);
            $table->enum('method', ['GET', 'POST', 'PUT', 'PATCH'])->default('POST');
            $table->json('headers')->nullable();
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_webhooks');
        Schema::dropIfExists('apis');
        Schema::dropIfExists('built_in_functions');
        Schema::dropIfExists('custom_functions');
        Schema::dropIfExists('custom_variables');
        Schema::dropIfExists('message_templates');
    }
};
