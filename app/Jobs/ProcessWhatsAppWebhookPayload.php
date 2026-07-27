<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPhoneIndex;
use App\Services\Bot\WhatsAppWebhookService;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhookPayload implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly array $payload)
    {
    }

    public function handle(WhatsAppWebhookService $managedBotService): void
    {
        $phoneNumberId = $this->extractPhoneNumberId($this->payload);

        if (!$phoneNumberId) {
            Log::warning('WhatsApp webhook: could not extract phone_number_id from payload.', [
                'payload' => $this->payload,
            ]);

            return;
        }

        $indexEntry = WhatsappPhoneIndex::where('phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();

        if (!$indexEntry) {
            Log::warning("WhatsApp webhook: no active tenant mapping for phone_number_id [{$phoneNumberId}].");

            return;
        }

        $tenant = Tenant::find($indexEntry->tenant_id);

        if (!$tenant || !$tenant->is_active) {
            Log::warning("WhatsApp webhook: tenant [{$indexEntry->tenant_id}] not found or inactive.");

            return;
        }

        tenancy()->initialize($tenant);

        try {
            $account = WhatsappAccount::where('phone_number_id', $phoneNumberId)->first();

            if (!$account) {
                Log::warning("WhatsApp webhook: phone_number_id [{$phoneNumberId}] indexed but no matching ".
                    "WhatsappAccount found in tenant [{$tenant->id}].");

                return;
            }

            if (!$account->is_active) {
                Log::info('WhatsApp webhook: message received for inactive account', ['account_id' => $account->id]);

                return;
            }

            // ── THE branch point ────────────────────────────────────────────
            if ($account->isConnectorMode()) {
                Log::debug('Using the connector mode');

                $this->forwardToConnector($account);
            } else {
                Log::debug('Using the managed bot mode');

                $managedBotService->handleWebhook($this->payload);
            }
        } catch (\Throwable $e) {
            Log::error('Webhook processing failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // let Laravel's retry/backoff handle transient failures
        } finally {
            tenancy()->end();
        }
    }

    private function forwardToConnector(WhatsappAccount $account): void
    {
        if (!$account->webhook_url) {
            Log::warning("Connector account [{$account->id}] received a message but has no webhook_url configured.");

            return;
        }

        try {
            $client = new Client(['timeout' => 5, 'connect_timeout' => 3]);
            $client->post($account->webhook_url, ['json' => $this->payload]);

            Log::info('Connector webhook forwarded', ['url' => $account->webhook_url]);
        } catch (\Throwable $e) {
            Log::warning('Connector webhook forward failed', [
                'url' => $account->webhook_url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Webhook processing failed permanently', [
            'error' => $e->getMessage(),
            'payload' => $this->payload,
        ]);
    }

    private function extractPhoneNumberId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
    }
}