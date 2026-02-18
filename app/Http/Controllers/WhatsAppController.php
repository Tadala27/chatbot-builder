<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function getTestAccount(Request $request)
    {
        $accessToken = 'EAARD7xiMKCkBQg8i4mGnTANvxSD9z4CPjArlZCLR1MbZCxvzru9ZAzzJ0DlIkSqYlMdTkeP3uVQfaoqBORwz5YMZAU2m6VlJfPbHdZANHfjsQr2ruRoRzp5v54E1rKpHx4P0g6URyQrlKyPcGRZAdMzo7tZA5zw293NVdtZCBPKUcd3omSWwgBwWH49m5uZCh2VVRZCcDdY8LZBKZB8gcWq3iuOI84L1jD6iZAj9JsmZBithq1QOC3ZBP7b7MPZAPzNtYdttfakOqooMHX6fobbDP898';


        if (!$accessToken) {
            return response()->json([
                'error' => 'Access token required'
            ], 400);
        }

        $client = new \GuzzleHttp\Client();

        try {

            // 1️⃣ Get businesses for user
            $response = $client->get('https://graph.facebook.com/v18.0/me/businesses', [
                'query' => [
                    'access_token' => $accessToken,
                ],
            ]);

            $businesses = json_decode($response->getBody(), true);

            if (empty($businesses['data'])) {
                return response()->json(['error' => 'No businesses found'], 404);
            }

            $businessId = $businesses['data'][0]['id'];

            // 2️⃣ Get WABA from business
            $response = $client->get("https://graph.facebook.com/v18.0/{$businessId}/client_whatsapp_business_accounts", [
                'query' => [
                    'access_token' => $accessToken,
                ],
            ]);

            $wabas = json_decode($response->getBody(), true);

            if (empty($wabas['data'])) {
                return response()->json(['error' => 'No WABA found'], 404);
            }

            $wabaId = $wabas['data'][0]['id'];

            // 3️⃣ Get phone numbers
            $response = $client->get("https://graph.facebook.com/v18.0/{$wabaId}/phone_numbers", [
                'query' => [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier',
                    'access_token' => $accessToken,
                ],
            ]);

            $phones = json_decode($response->getBody(), true);

            if (empty($phones['data'])) {
                return response()->json(['error' => 'No phone numbers found'], 404);
            }

            $phone = $phones['data'][0];

            Log::info('Get Test Account', [
                'waba_id' => $wabaId,
                'phone_number_id' => $phone['id'],
                'phone_number' => preg_replace('/\D/', '', $phone['display_phone_number']),
                'display_phone_number' => $phone['display_phone_number'],
                'verified_name' => $phone['verified_name'] ?? null,
                'quality_rating' => $phone['quality_rating'] ?? 'UNKNOWN',
                'messaging_limit' => $phone['messaging_limit_tier'] ?? 'TIER_1K',
            ]);

            return response()->json([
                'waba_id' => $wabaId,
                'phone_number_id' => $phone['id'],
                'phone_number' => preg_replace('/\D/', '', $phone['display_phone_number']),
                'display_phone_number' => $phone['display_phone_number'],
                'verified_name' => $phone['verified_name'] ?? null,
                'quality_rating' => $phone['quality_rating'] ?? 'UNKNOWN',
                'messaging_limit' => $phone['messaging_limit_tier'] ?? 'TIER_1K',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}