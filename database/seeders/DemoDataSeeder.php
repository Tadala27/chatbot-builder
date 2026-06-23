<?php

namespace Database\Seeders;

use App\Models\SystemUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = SystemUser::firstOrCreate(
            [
                'email' => 'admin@chatbot.com',
            ],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'timezone' => 'Africa/Blantyre',
                'locale' => 'en',
                'email_verified_at' => now(),
            ]
        );

        if (!$superAdmin->hasRole('super-admin')) {
            $superAdmin->assignRole('super-admin');
        }

        $admin = SystemUser::firstOrCreate(
            [
                'email' => 'admin@demo.com',
            ],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'timezone' => 'Africa/Blantyre',
                'locale' => 'en',
                'email_verified_at' => now(),
            ]
        );

        if (!$admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }
    }
}