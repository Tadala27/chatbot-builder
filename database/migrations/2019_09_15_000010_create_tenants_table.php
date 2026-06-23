<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('db_schema')->unique();
            $table->enum('deployment_mode', ['shared', 'dedicated', 'self_hosted'])->default('shared');
            // Your extras
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);

            $table->enum('subscription_tier', ['free', 'starter', 'professional', 'enterprise'])
                  ->default('free');
            $table->timestamp('subscription_expires_at')->nullable();

            $table->integer('max_flows')->default(3);
            $table->integer('max_conversations_per_month')->default(1000);

            $table->json('settings')->nullable();
            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
