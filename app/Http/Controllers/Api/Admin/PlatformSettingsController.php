<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class PlatformSettingsController extends Controller
{
    private string $cacheKey = 'platform_settings';

    public function index(): JsonResponse
    {
        $settings = Cache::rememberForever($this->cacheKey, fn () => $this->defaults());

        return response()->json($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Defaults applied to new tenants
            'default_max_flows' => ['sometimes', 'integer', 'min:1'],
            'default_max_conversations_per_month' => ['sometimes', 'integer', 'min:0'],
            'default_subscription_tier' => ['sometimes', 'string', Rule::in(['free', 'starter', 'professional', 'enterprise'])],

            // Feature flags
            'features' => ['sometimes', 'array'],
            'features.registration_open' => ['sometimes', 'boolean'],
            'features.maintenance_mode' => ['sometimes', 'boolean'],
            'features.whatsapp_enabled' => ['sometimes', 'boolean'],
            'features.facebook_enabled' => ['sometimes', 'boolean'],

            // Branding
            'branding' => ['sometimes', 'array'],
            'branding.app_name' => ['sometimes', 'string', 'max:100'],
            'branding.support_email' => ['sometimes', 'email'],
            'branding.website_url' => ['sometimes', 'url'],
        ]);

        $current = Cache::get($this->cacheKey, $this->defaults());
        $updated = array_replace_recursive($current, $validated);

        Cache::forever($this->cacheKey, $updated);

        // Persist to DB if you have a settings table; for now cache is source of truth
        \DB::table('platform_settings')->updateOrInsert(
            ['key' => 'global'],
            ['value' => json_encode($updated), 'updated_at' => now()]
        );

        return response()->json([
            'message' => 'Platform settings updated.',
            'settings' => $updated,
        ]);
    }

    private function defaults(): array
    {
        return [
            'default_max_flows' => 3,
            'default_max_conversations_per_month' => 1000,
            'default_subscription_tier' => 'free',
            'features' => [
                'registration_open' => true,
                'maintenance_mode' => false,
                'whatsapp_enabled' => true,
                'facebook_enabled' => true,
            ],
            'branding' => [
                'app_name' => config('app.name'),
                'support_email' => config('mail.from.address'),
                'website_url' => config('app.url'),
            ],
        ];
    }
}
