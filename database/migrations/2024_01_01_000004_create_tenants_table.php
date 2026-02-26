<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | TENANTS
        |--------------------------------------------------------------------------
        */
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->unique()->nullable();
            $table->string('database')->nullable(); // ✅ RESTORED from old
            $table->boolean('is_active')->default(true);
            $table->enum('subscription_tier', ['free', 'starter', 'professional', 'enterprise'])->default('free');
            $table->timestamp('subscription_expires_at')->nullable();
            $table->integer('max_flows')->default(3);
            $table->integer('max_conversations_per_month')->default(1000);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        /*
        |--------------------------------------------------------------------------
        | USERS (Extend default Laravel users table)
        |--------------------------------------------------------------------------
        */
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->string('avatar')->nullable()->after('is_super_admin');
            $table->string('timezone')->default('UTC')->after('avatar');
            $table->string('locale')->default('en')->after('timezone');
        });

        /*
        |--------------------------------------------------------------------------
        | TENANT USERS (Pivot)
        |--------------------------------------------------------------------------
        */
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | WHATSAPP ACCOUNTS
        |--------------------------------------------------------------------------
        */
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id')->unique();
            $table->string('phone_number_id')->unique();
            $table->string('phone_number', 20);
            $table->string('display_phone_number', 20)->nullable();
            $table->string('verified_name')->nullable();
            $table->enum('quality_rating', ['GREEN', 'YELLOW', 'RED', 'UNKNOWN'])->default('UNKNOWN');
            $table->enum('messaging_limit', ['TIER_1K', 'TIER_10K', 'TIER_100K', 'TIER_UNLIMITED'])->default('TIER_1K');
            $table->text('access_token');
            $table->string('webhook_verify_token')->nullable(); // ✅ RESTORED
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'is_active']);
        });

        /*
        |--------------------------------------------------------------------------
        | FACEBOOK BUSINESS ACCOUNTS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('facebook_business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('fb_business_id');
            $table->string('fb_user_id')->nullable();
            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

        /*
        |--------------------------------------------------------------------------
        | FLOWS
        |--------------------------------------------------------------------------
        */
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedBigInteger('current_published_version_id')->nullable();
            $table->string('default_language', 10)->default('en');
            $table->json('supported_languages')->nullable();
            $table->json('settings')->nullable();
            $table->text('fallback_message')->nullable();
            $table->text('welcome_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | FLOW VERSIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->integer('version_number');
            $table->enum('status', ['draft', 'published', 'locked'])->default('draft');
            $table->unsignedBigInteger('start_node_id')->nullable();
            $table->unsignedBigInteger('fallback_node_id')->nullable();
            $table->boolean('ai_fallback_enabled')->default(false);
            $table->json('ai_fallback_config')->nullable();
            $table->text('changelog')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['flow_id', 'version_number']);
        });

        /*
        |--------------------------------------------------------------------------
        | FLOW NODES
        |--------------------------------------------------------------------------
        */
        Schema::create('flow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_version_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid');
            $table->enum('type', [
                'message',
                'input',
                'list',
                'buttons',
                'condition',
                'api_call',
                'function',
                'delay',
                'webhook',
                'handoff',
                'subflow',
                'loop',
                'end',
                'media', 
                'trigger', 
            ]);
            $table->string('label')->nullable();
            $table->json('content')->nullable();
            $table->json('config')->nullable();
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->integer('retry_limit')->default(0);
            $table->unsignedBigInteger('retry_fallback_node_id')->nullable();
            $table->integer('timeout_seconds')->nullable();
            $table->unsignedBigInteger('timeout_next_node_id')->nullable();
            $table->boolean('is_entry_point')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->string('ab_group')->nullable();
            $table->integer('ab_weight')->default(100);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | NODE ACTIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('flow_node_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_node_id')->constrained()->cascadeOnDelete();
            $table->string('source_item_id')->nullable();
            $table->string('source_item_type')->nullable()->default('node');
            $table->enum('trigger_event', ['on_enter', 'on_exit', 'on_success', 'on_failure', 'on_timeout', 'on_retry']);
            $table->enum('action_type', ['save_variable', 'update_variable', 'delete_variable', 'api_call', 'execute_function', 'delay', 'tag_user', 'assign_agent', 'emit_event', 'webhook_call']);
            $table->integer('execution_order')->default(0);
            $table->json('config')->nullable();
            $table->boolean('continue_on_failure')->default(true);
            $table->timestamps();

            $table->index(['flow_node_id', 'source_item_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | CONDITIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('condition_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_version_id')->constrained()->cascadeOnDelete();
            $table->enum('logical_operator', ['AND', 'OR'])->default('AND');
            $table->timestamps();
        });

        Schema::create('conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_group_id')->constrained()->cascadeOnDelete();
            $table->string('variable_key');
            $table->enum('operator', [
                'equals',
                'not_equals',
                'contains',
                'not_contains',
                'greater_than',
                'less_than',
                'greater_than_or_equal',
                'less_than_or_equal',
                'exists',
                'not_exists',
            ]);
            $table->string('value')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | MESSAGE TEMPLATES - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
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

        /*
        |--------------------------------------------------------------------------
        | GLOBAL VARIABLES - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('global_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->enum('data_type', ['string', 'number', 'boolean', 'json', 'date'])->default('string');
            $table->boolean('is_encrypted')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        /*
        |--------------------------------------------------------------------------
        | CONVERSATIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_user_phone', 20);
            $table->string('whatsapp_user_name')->nullable();
            $table->enum('status', ['active', 'completed', 'handed_off', 'abandoned'])->default('active');
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_message_at')->useCurrent();
            $table->integer('message_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['whatsapp_user_phone', 'status']);
            $table->index('last_message_at'); // ✅ RESTORED
        });

        /*
        |--------------------------------------------------------------------------
        | MESSAGES
        |--------------------------------------------------------------------------
        */
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_message_id')->unique()->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', ['text', 'image', 'video', 'audio', 'document', 'location', 'interactive', 'template']);
            $table->json('content');
            $table->enum('status', ['sent', 'delivered', 'read', 'failed'])->default('sent');
            $table->foreignId('flow_node_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'direction']);
            $table->index('whatsapp_message_id'); // ✅ RESTORED
        });

        Schema::create('custom_variables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flow_id');
            $table->string('name');
            $table->enum('save_in', ['bot_variables', 'user_properties']);
            $table->boolean('use_in_js')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();

            $table->foreign('flow_id')->references('id')->on('flows')->onDelete('cascade');
        });

        /*
        |--------------------------------------------------------------------------
        | CONVERSATION VARIABLES
        |--------------------------------------------------------------------------
        */
        Schema::create('conversation_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            $table->foreignId('custom_variable_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'key']);
        });
        /*
        |--------------------------------------------------------------------------
        | VARIABLE LOGS
        |--------------------------------------------------------------------------
        */
        Schema::create('conversation_variable_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        /*
        |--------------------------------------------------------------------------
        | CONVERSATION CONTEXTS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('conversation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->json('variables');
            $table->unsignedBigInteger('last_node_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('conversation_id');
            $table->index('expires_at');
        });

        /*
        |--------------------------------------------------------------------------
        | AGENT HANDOVER LOGS
        |--------------------------------------------------------------------------
        */
        Schema::create('agent_handover_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | EXECUTION LOGS
        |--------------------------------------------------------------------------
        */
        Schema::create('flow_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | NODE METRICS
        |--------------------------------------------------------------------------
        */
        Schema::create('node_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_node_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->integer('entered_count')->default(0);
            $table->integer('completed_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestamps();
            $table->unique(['flow_node_id', 'metric_date']);
        });

        /*
        |--------------------------------------------------------------------------
        | ANALYTICS EVENTS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('event_type', [
                'conversation_started',
                'conversation_completed',
                'conversation_abandoned',
                'node_entered',
                'node_completed',
                'condition_evaluated',
                'function_executed',
                'api_called',
                'handoff_initiated',
                'error_occurred'
            ]);
            $table->unsignedBigInteger('node_id')->nullable(); // Changed to bigint to match flow_nodes.id
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'flow_id', 'created_at']);
            $table->index('event_type');
        });

        /*
        |--------------------------------------------------------------------------
        | CUSTOM FUNCTIONS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('custom_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->enum('function_type', ['javascript', 'api_call', 'webhook', 'built_in']);
            $table->text('code')->nullable();
            $table->json('parameters')->nullable();
            $table->string('return_type', 50)->nullable();
            $table->boolean('is_async')->default(false);
            $table->integer('timeout_seconds')->default(30);
            $table->boolean('is_active')->default(true);
            $table->json('test_cases')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        /*
        |--------------------------------------------------------------------------
        | BUILT-IN FUNCTIONS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('built_in_functions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('category', ['date_time', 'string', 'logical', 'formatting', 'math', 'array']);
            $table->text('description')->nullable();
            $table->text('syntax')->nullable();
            $table->json('parameters')->nullable();
            $table->string('return_type', 50)->nullable();
            $table->json('examples')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | API INTEGRATIONS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('api_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['rest', 'graphql', 'soap', 'webhook'])->default('rest');
            $table->string('base_url', 500);
            $table->enum('auth_type', ['none', 'basic', 'bearer', 'api_key', 'oauth2'])->default('none');
            $table->json('auth_config')->nullable();
            $table->json('headers')->nullable();
            $table->integer('timeout_seconds')->default(30);
            $table->integer('retry_attempts')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('tenant_id');
        });

        /*
        |--------------------------------------------------------------------------
        | OUTGOING WEBHOOKS - ✅ RESTORED
        |--------------------------------------------------------------------------
        */
        Schema::create('outgoing_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('api_integrations');
        Schema::dropIfExists('built_in_functions');
        Schema::dropIfExists('custom_functions');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('node_metrics');
        Schema::dropIfExists('flow_execution_logs');
        Schema::dropIfExists('agent_handover_logs');
        Schema::dropIfExists('conversation_contexts');
        Schema::dropIfExists('conversation_variable_logs');
        Schema::dropIfExists('conversation_variables');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('global_variables');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('conditions');
        Schema::dropIfExists('condition_groups');
        Schema::dropIfExists('flow_node_actions');
        Schema::dropIfExists('flow_nodes');
        Schema::dropIfExists('flow_versions');
        Schema::dropIfExists('flows');
        Schema::dropIfExists('facebook_business_accounts');
        Schema::dropIfExists('whatsapp_accounts');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};