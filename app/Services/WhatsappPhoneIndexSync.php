<?php

// app/Services/WhatsappPhoneIndexSync.php

namespace App\Services;

use App\Models\ConnectorKeyIndex;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPhoneIndex;

class WhatsappPhoneIndexSync
{
    private function landlordConnection(): string
    {
        return config('tenancy.landlord_connection', 'landlord');
    }

    /**
     * Call on connect — populates both indexes for a freshly created
     * connector account. Requires tenant() to resolve (i.e. must be called
     * while the tenant's own DB connection is active).
     */
    public function upsert(WhatsappAccount $account): void
    {
        $tenant = tenant();

        if (!$tenant) {
            return;
        }

        WhatsappPhoneIndex::on($this->landlordConnection())->updateOrCreate(
            ['phone_number_id' => $account->phone_number_id],
            [
                'tenant_id' => $tenant->id,
                'verify_token' => $account->webhook_verify_token,
            ]
        );

        if ($account->connector_api_key) {
            $this->syncKeyIndex($account, $tenant->id);
        }
    }

    /**
     * Call specifically after rotateConnectorApiKey() — the OLD key row
     * must be removed (it no longer authenticates anything) and the NEW
     * key inserted. Also called internally by upsert() above.
     */
    public function syncKeyIndex(WhatsappAccount $account, ?string $tenantId = null): void
    {
        $tenantId ??= tenant()?->id;

        if (!$tenantId || !$account->connector_api_key) {
            return;
        }

        // Remove any stale row for this account's tenant first — handles
        // the rotation case where the OLD key (different value) needs to
        // stop working the instant a new one is issued.
        ConnectorKeyIndex::on($this->landlordConnection())
            ->where('tenant_id', $tenantId)
            ->delete();

        ConnectorKeyIndex::on($this->landlordConnection())->create([
            'connector_api_key' => $account->connector_api_key,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Call on disconnect — removes both index entries so inbound stops
     * forwarding and the key stops authenticating outbound sends.
     */
    public function remove(WhatsappAccount $account): void
    {
        WhatsappPhoneIndex::on($this->landlordConnection())
            ->where('phone_number_id', $account->phone_number_id)
            ->delete();

        if ($account->connector_api_key) {
            ConnectorKeyIndex::on($this->landlordConnection())
                ->where('connector_api_key', $account->connector_api_key)
                ->delete();
        }
    }

    public function hasIndex(WhatsappAccount $account): bool
    {
        return WhatsappPhoneIndex::on($this->landlordConnection())
            ->where('phone_number_id', $account->phone_number_id)
            ->exists();
    }
}