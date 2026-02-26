<?php

namespace Database\Seeders;

use Carbon\Carbon;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WhatsappAccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('whatsapp_accounts')->insert([
            'tenant_id' => 1,
            'waba_id' => '2124195388053485',
            'phone_number_id' => '632142966655683',
            'phone_number' => '+1 555 656 9855',
            'display_phone_number' => '+1 555 656 9855',
            'verified_name' => 'Test Number',
            'quality_rating' => 'GREEN',
            'messaging_limit' => 'TIER_1K',
            'access_token' => encrypt('EAARD7xiMKCkBPHIUpZCpkZBD2hdLYJnL8jZAR9DZB15OZCrKNcP6Q4CPu0ZCpNgmd19YicPcv2XVUwMqZB8ydJ8yIJYZBVYlAkJT9ovEYDmPwSlEfZAL7eMhmUp0IsmCwWrKxhKxdFGBGptZAstmMKnQZCKeQJvAdwFL5ZBDsinlGqcKgdtzAwyCVinvaHAldBIcOwZDZD'),
            'webhook_verify_token' => 'MySecretToken',
            'is_active' => true,
            'last_synced_at' => Carbon::now(),
            'metadata' => json_encode([
                'business_name' => 'Demo Business Ltd',
                'business_email' => 'demo@example.com',
                'timezone' => 'Africa/Blantyre'
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}