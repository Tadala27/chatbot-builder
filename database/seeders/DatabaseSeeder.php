<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,   // landlord roles (super-admin, etc.)
            BuiltInFunctionsSeeder::class,       // global built-in functions
            DemoDataSeeder::class,
            
        ]);

        // Optionally create a demo tenant (triggers observer → provisions DB → runs tenant seeders)
        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoTenantSeeder::class);
        }
    }
}