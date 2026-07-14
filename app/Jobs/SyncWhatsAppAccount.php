<?php

namespace App\Jobs;

use App\Models\WhatsappAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class SyncWhatsAppAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public WhatsappAccount $account
    ) {
        $this->onQueue('sync');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            if (!$this->account->is_active) {
                Log::info('Skipping sync for inactive account', [
                    'account_id' => $this->account->id,
                ]);
                return;
            }

            Log::info('Syncing WhatsApp account', [
                'account_id' => $this->account->id,
                'phone_number' => $this->account->phone_number,
            ]);

            $client = new Client();

            // Get phone number details
            $response = $client->get(
                "https://graph.facebook.com/v18.0/{$this->account->phone_number_id}",
                [
                    'query' => [
                        'access_token' => decrypt($this->account->access_token),
                        'fields' => 'verified_name,code_verification_status,display_phone_number,quality_rating,messaging_limit_tier',
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            // Update account
            $this->account->updateFromMeta([
                'verified_name' => $data['verified_name'] ?? $this->account->verified_name,
                'quality_rating' => $data['quality_rating'] ?? $this->account->quality_rating,
                'messaging_limit' => $data['messaging_limit_tier'] ?? $this->account->messaging_limit,
                'metadata' => $data,
            ]);

            Log::info('WhatsApp account synced successfully', [
                'account_id' => $this->account->id,
                'quality_rating' => $this->account->quality_rating,
                'messaging_limit' => $this->account->messaging_limit,
            ]);

            // Check for quality issues
            if (in_array($this->account->quality_rating, ['YELLOW', 'RED'])) {
                Log::warning('WhatsApp account has quality issues', [
                    'account_id' => $this->account->id,
                    'quality_rating' => $this->account->quality_rating,
                    'phone_number' => $this->account->phone_number,
                ]);

            }
        } catch (\Exception $e) {
            Log::error('Failed to sync WhatsApp account', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp account sync failed permanently', [
            'account_id' => $this->account->id,
            'error' => $exception->getMessage(),
        ]);
    }
}