<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Tenant Management
            'view tenants',
            'create tenants',
            'edit tenants',
            'delete tenants',
            'manage tenant subscriptions',
            'impersonate tenant',

            // System / Platform
            'view system logs',
            'manage platform settings',
            'view platform analytics',

            // Central User Management
            'view admin users',
            'create admin users',
            'edit admin users',
            'delete admin users',
            'assign admin roles',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'system']
            );
        }

        // Super Admin — everything
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'system']);
        $superAdmin->syncPermissions(Permission::where('guard_name', 'system')->get());

        // Support Admin — can view and manage tenants but not delete or billing
        $Admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'system']);
        $Admin->syncPermissions([
            'view tenants',
            'edit tenants',
            'impersonate tenant',
            'view system logs',
            'view platform analytics',
            'view admin users',
        ]);

        // Billing Admin — subscription management only
        $billingAdmin = Role::firstOrCreate(['name' => 'billing-admin', 'guard_name' => 'system']);
        $billingAdmin->syncPermissions([
            'view tenants',
            'manage tenant subscriptions',
            'view platform analytics',
        ]);
    }
}