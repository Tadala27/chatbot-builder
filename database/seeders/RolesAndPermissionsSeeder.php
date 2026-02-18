<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // Tenant Management (Super Admin only)
            'view tenants',
            'create tenants',
            'edit tenants',
            'delete tenants',
            'manage tenant subscriptions',

            // User Management
            'view users',
            'invite users',
            'edit users',
            'delete users',
            'assign roles',

            // Chatbot Management
            'view chatbots',
            'create chatbots',
            'edit chatbots',
            'delete chatbots',
            'publish chatbots',
            'test chatbots',
            'duplicate chatbots',

            // Flow Builder
            'edit flows',
            'create nodes',
            'edit nodes',
            'delete nodes',
            'create edges',
            'delete edges',
            'validate flows',

            // Variables
            'view variables',
            'create variables',
            'edit variables',
            'delete variables',

            // Functions
            'view functions',
            'create functions',
            'edit functions',
            'delete functions',
            'execute functions',
            'test functions',

            // WhatsApp Accounts
            'view whatsapp-accounts',
            'connect whatsapp-accounts',
            'disconnect whatsapp-accounts',
            'manage whatsapp-accounts',

            // Conversations
            'view conversations',
            'view conversation-details',
            'export conversations',
            'delete conversations',
            'handoff conversations',

            // Analytics
            'view analytics',
            'view detailed-analytics',
            'export analytics',

            // Integrations
            'view integrations',
            'create integrations',
            'edit integrations',
            'delete integrations',
            'test integrations',

            // Templates
            'view templates',
            'create templates',
            'edit templates',
            'delete templates',
            'submit templates',

            // Webhooks
            'view webhooks',
            'create webhooks',
            'edit webhooks',
            'delete webhooks',

            // Settings
            'view settings',
            'manage settings',
            'manage billing',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions

        // 1. Super Admin (system-wide)
        $superAdmin = Role::create(['name' => 'super-admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // 2. Tenant Admin (full access within tenant)
        $tenantAdmin = Role::create(['name' => 'tenant-admin']);
        $tenantAdmin->givePermissionTo([
            // User management
            'view users',
            'invite users',
            'edit users',
            'delete users',
            'assign roles',

            // Chatbot management
            'view chatbots',
            'create chatbots',
            'edit chatbots',
            'delete chatbots',
            'publish chatbots',
            'test chatbots',
            'duplicate chatbots',

            // Flow builder
            'edit flows',
            'create nodes',
            'edit nodes',
            'delete nodes',
            'create edges',
            'delete edges',
            'validate flows',

            // Variables
            'view variables',
            'create variables',
            'edit variables',
            'delete variables',

            // Functions
            'view functions',
            'create functions',
            'edit functions',
            'delete functions',
            'execute functions',
            'test functions',

            // WhatsApp
            'view whatsapp-accounts',
            'connect whatsapp-accounts',
            'disconnect whatsapp-accounts',
            'manage whatsapp-accounts',

            // Conversations
            'view conversations',
            'view conversation-details',
            'export conversations',
            'delete conversations',
            'handoff conversations',

            // Analytics
            'view analytics',
            'view detailed-analytics',
            'export analytics',

            // Integrations
            'view integrations',
            'create integrations',
            'edit integrations',
            'delete integrations',
            'test integrations',

            // Templates
            'view templates',
            'create templates',
            'edit templates',
            'delete templates',
            'submit templates',

            // Webhooks
            'view webhooks',
            'create webhooks',
            'edit webhooks',
            'delete webhooks',

            // Settings
            'view settings',
            'manage settings',
            'manage billing',
        ]);

        // 3. Bot Builder (can create/edit bots)
        $botBuilder = Role::create(['name' => 'bot-builder']);
        $botBuilder->givePermissionTo([
            'view chatbots',
            'create chatbots',
            'edit chatbots',
            'test chatbots',
            'duplicate chatbots',
            'edit flows',
            'create nodes',
            'edit nodes',
            'delete nodes',
            'create edges',
            'delete edges',
            'validate flows',
            'view variables',
            'create variables',
            'edit variables',
            'delete variables',
            'view functions',
            'execute functions',
            'test functions',
            'view conversations',
            'view conversation-details',
            'view analytics',
            'view templates',
            'view integrations',
        ]);

        // 4. Agent (handles conversations)
        $agent = Role::create(['name' => 'agent']);
        $agent->givePermissionTo([
            'view conversations',
            'view conversation-details',
            'handoff conversations',
            'view chatbots',
        ]);

        // 5. Viewer (read-only)
        $viewer = Role::create(['name' => 'viewer']);
        $viewer->givePermissionTo([
            'view chatbots',
            'view conversations',
            'view analytics',
            'view templates',
            'view integrations',
            'view functions',
            'view variables',
        ]);
    }
}