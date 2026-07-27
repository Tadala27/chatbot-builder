<?php

namespace App\Http\Middleware;

use App\Models\ConnectorKeyIndex;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates incoming connector API requests.
 *
 * External systems send their API key in the X-Connector-Key header.
 * We look it up in the central WhatsappPhoneIndex table (landlord DB),
 * resolve the tenant, initialise the tenancy context, then find the
 * matching WhatsappAccount in the tenant DB and attach it to the request
 * as the 'connectorAccount' attribute.
 *
 * Route: POST /api/connector/messages
 * Header: X-Connector-Key: <key>
 */
class ConnectorAuthenticate
{
    public function handle(Request $request, \Closure $next): Response
    {
        $key = $request->header('X-Connector-Key');

        if (empty($key)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-Connector-Key header.',
            ], 401);
        }

        $index = ConnectorKeyIndex::where('connector_api_key', $key)
        ->first();

        if (!$index) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired connector API key.',
            ], 401);
        }

        // Initialise tenancy for this request so tenant DB queries work.
        $tenant = Tenant::find($index->tenant_id);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
            ], 401);
        }

        tenancy()->initialize($tenant);

        // Now find the WhatsApp account in the tenant DB.
        $account = WhatsappAccount::where('connector_api_key', $index->connector_api_key)
            ->where('mode', 'connector')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'No active connector account found for this key.',
            ], 401);
        }

        // Attach to the request for the controller to consume.
        $request->attributes->set('connectorAccount', $account);

        return $next($request);
    }
}