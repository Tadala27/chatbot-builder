<?php

// app/Jobs/ProcessWhatsAppWebhookPayload.php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WhatsappPhoneIndex;
use App\Services\Bot\WhatsAppWebhookService;
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

    public function handle(WhatsAppWebhookService $service): void
    {
        $phoneNumberId = $this->extractPhoneNumberId($this->payload);

        if (!$phoneNumberId) {
            Log::warning('WhatsApp webhook: could not extract phone_number_id from payload.', [
                'payload' => $this->payload,
            ]);

            return;
        }

        // ── Resolve tenant from the central index (landlord connection) ───
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

        // ── Switch into the tenant database ────────────────────────────────
        tenancy()->initialize($tenant);

        try {
            $service->handleWebhook($this->payload);
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