<?php

// app/Http/Middleware/ResolveTenantFromConnectorKey.php

namespace App\Http\Middleware;

use App\Models\ConnectorKeyIndex;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which tenant's database to switch into using ONLY the
 * X-Connector-Key header — no tenant slug anywhere in the URL. This is
 * what lets every tenant paste the exact same fixed outbound URL
 * (/api/connector/messages) into their own external system, regardless of
 * who they are.
 *
 * Sequence:
 *   1. Look up the key in connector_key_index (landlord connection — the
 *      default at this point, since nothing has switched yet).
 *   2. Switch into that tenant's database.
 *   3. Re-verify the key against the actual WhatsappAccount row (defense
 *      against a stale index entry after rotation/disconnect).
 *   4. Attach the account to the request for the controller to use.
 *   5. Always end tenancy in `finally`, after the controller has run.
 */
class ResolveTenantFromConnectorKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Connector-Key');

        if (!$key) {
            return response()->json(['message' => 'Missing X-Connector-Key header.'], 401);
        }

        $indexEntry = ConnectorKeyIndex::where('connector_api_key', $key)->first();

        if (!$indexEntry) {
            return response()->json(['message' => 'Invalid connector key.'], 401);
        }

        $tenant = Tenant::find($indexEntry->tenant_id);

        if (!$tenant || !$tenant->is_active) {
            return response()->json(['message' => 'Invalid connector key.'], 401);
        }

        tenancy()->initialize($tenant);

        try {
            $account = WhatsappAccount::where('connector_api_key', $key)
                ->where('mode', 'connector')
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json(['message' => 'Invalid or inactive connector key.'], 401);
            }

            $request->attributes->set('connectorAccount', $account);

            return $next($request);
        } finally {
            tenancy()->end();
        }
    }
}
