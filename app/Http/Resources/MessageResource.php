<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Services\Tenant\TenantStorageManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'whatsapp_message_id' => $this->whatsapp_message_id,
            'reply_to_wamid' => $this->reply_to_wamid,
            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'content' => $this->content,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sender_type' => $this->metadata['sender_type'] ?? ($this->direction === 'outbound' ? 'bot' : 'contact'),
            'sender_name' => $this->sender_name,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'media_url' => $this->resolveMediaUrl(),
            'quoted_message' => $this->when(
                isset($this->resource->quoted_message),
                fn () => $this->formatQuotedMessage($this->resource->quoted_message),
            ),
        ];
    }

    private function resolveMediaUrl(): ?string
    {
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];

        if (!in_array($this->message_type, $mediaTypes, true)) {
            return null;
        }

        $content = $this->content ?? [];

        // Option 1: use the pre-signed URL stored at download/upload time.
        // Skip it only if it contains %5C (URL-encoded backslash from the
        // Windows path bug before the TenantStorageManager fix was deployed).
        $storedUrl = $content['url'] ?? null;
        if ($storedUrl && !str_contains($storedUrl, '%5C') && !str_contains($storedUrl, '\\')) {
            return $storedUrl;
        }

        // Option 2: regenerate from storage_path for tenant S3 files.
        // storage_path is always clean (forward slashes) — the bug only
        // affected the signed URL that was generated with the bad root.
        $storagePath = $content['storage_path'] ?? null;
        $storageDriver = $content['storage_driver'] ?? null;

        if ($storagePath && $storageDriver === 'tenant') {
            try {
                return TenantStorageManager::temporaryUrl(
                    $storagePath,
                    minutes: 60 * 24 * 7,
                );
            } catch (\Exception $e) {
                Log::warning('[MessageResource] Failed to regenerate S3 URL', [
                    'message_id' => $this->id,
                    'path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Option 3: legacy public disk — build a tenant-scoped serve URL.
        $storedFilename = $content['stored_filename'] ?? null;
        if ($storedFilename) {
            return $this->buildTenantMediaUrl($storedFilename);
        }

        return null;
    }

    private function buildTenantMediaUrl(string $storedFilename): ?string
    {
        $tenant = tenant();
        if (!$tenant) {
            return null;
        }

        $domain = $tenant->primaryDomain();
        if (!$domain) {
            return null;
        }

        return rtrim($domain->url, '/').'/tenant/media/file/'.$storedFilename;
    }

    private function formatQuotedMessage(mixed $quoted): ?array
    {
        if (!$quoted) {
            return null;
        }

        if ($quoted instanceof Message) {
            return [
                'id' => $quoted->id,
                'direction' => $quoted->direction,
                'message_type' => $quoted->message_type,
                'content' => $quoted->content,
                'sender_name' => $quoted->metadata['sender_name'] ?? null,
                'sender_type' => $quoted->metadata['sender_type'] ?? ($quoted->direction === 'outbound' ? 'bot' : 'contact'),
            ];
        }

        // Already an array (set manually in controller)
        return is_array($quoted) ? $quoted : null;
    }
}