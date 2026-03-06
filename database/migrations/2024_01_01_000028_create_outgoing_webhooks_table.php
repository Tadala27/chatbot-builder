<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
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
    }
};
