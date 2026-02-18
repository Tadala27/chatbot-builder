<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create Super Admin
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@chatbot.com',
            'password' => Hash::make('password'),
            'is_super_admin' => true,
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $superAdmin->assignRole('super-admin');

        // Create Tenant 1: TechCorp
        $tenant1 = Tenant::create([
            'name' => 'TechCorp Solutions',
            'slug' => 'techcorp',
            'domain' => 'techcorp.chatbot.local',
            'is_active' => true,
            'database' => 'tenant_techcorp', // Add this line
            'subscription_tier' => 'professional',
            'subscription_expires_at' => now()->addYear(),
            'max_flows' => 10,
            'max_conversations_per_month' => 10000,
            'settings' => [
                'company_size' => '50-100',
                'industry' => 'Technology',
            ],
        ]);

        // Create Admin for Tenant 1
        $admin1 = User::create([
            'name' => 'John Doe',
            'email' => 'john@techcorp.com',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $admin1->assignRole('tenant-admin');
        $tenant1->users()->attach($admin1->id, ['is_primary' => true, 'joined_at' => now()]);

        // Create Bot Builder for Tenant 1
        $builder1 = User::create([
            'name' => 'Jane Smith',
            'email' => 'jane@techcorp.com',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $builder1->assignRole('bot-builder');
        $tenant1->users()->attach($builder1->id, ['is_primary' => false, 'joined_at' => now()]);

        // Create Agent for Tenant 1
        $agent1 = User::create([
            'name' => 'Mike Johnson',
            'email' => 'mike@techcorp.com',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $agent1->assignRole('agent');
        $tenant1->users()->attach($agent1->id, ['is_primary' => false, 'joined_at' => now()]);

        // Create Tenant 2: RetailHub
        $tenant2 = Tenant::create([
            'name' => 'RetailHub Commerce',
            'slug' => 'retailhub',
            'domain' => 'retailhub.chatbot.local',
            'is_active' => true,
            'database' => 'tenant_retailhub', // Add this line
            'subscription_tier' => 'starter',
            'subscription_expires_at' => now()->addMonths(6),
            'max_flows' => 5,
            'max_conversations_per_month' => 5000,
            'settings' => [
                'company_size' => '10-50',
                'industry' => 'Retail',
            ],
        ]);

        // Create Admin for Tenant 2
        $admin2 = User::create([
            'name' => 'Sarah Williams',
            'email' => 'sarah@retailhub.com',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $admin2->assignRole('tenant-admin');
        $tenant2->users()->attach($admin2->id, ['is_primary' => true, 'joined_at' => now()]);

        // Create Bot Builder for Tenant 2
        $builder2 = User::create([
            'name' => 'Tom Brown',
            'email' => 'tom@retailhub.com',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $builder2->assignRole('bot-builder');
        $tenant2->users()->attach($builder2->id, ['is_primary' => false, 'joined_at' => now()]);

        // Create Viewer for Tenant 2
        $viewer2 = User::create([
            'name' => 'Lisa Davis',
            'email' => 'lisa@retailhub.com',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Blantyre',
            'locale' => 'en',
        ]);
        $viewer2->assignRole('viewer');
        $tenant2->users()->attach($viewer2->id, ['is_primary' => false, 'joined_at' => now()]);

        $this->command->info('Demo data seeded successfully!');
        $this->command->info('Super Admin: admin@chatbot.com / password');
        $this->command->info('Tenant 1 (TechCorp): john@techcorp.com / password');
        $this->command->info('Tenant 2 (RetailHub): sarah@retailhub.com / password');
    }
}