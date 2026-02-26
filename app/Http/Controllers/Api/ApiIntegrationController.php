<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiIntegration;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;

class ApiIntegrationController extends Controller
{
    /**
     * Get all API integrations for tenant
     */
    public function index(): JsonResponse
    {
        $tenant = Tenant::current();

        $integrations = ApiIntegration::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $integrations,
        ]);
    }

    /**
     * Store a new API integration
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|in:rest,graphql,soap,webhook',
            'base_url' => 'required|url|max:500',
            'auth_type' => 'required|in:none,basic,bearer,api_key,oauth2',
            'auth_config' => 'nullable|array',
            'headers' => 'nullable|array',
            'timeout_seconds' => 'integer|min:1|max:300',
            'retry_attempts' => 'integer|min:0|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $integration = ApiIntegration::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'type' => $request->type,
            'base_url' => $request->base_url,
            'auth_type' => $request->auth_type,
            'auth_config' => $request->auth_config,
            'headers' => $request->headers,
            'timeout_seconds' => $request->timeout_seconds ?? 30,
            'retry_attempts' => $request->retry_attempts ?? 3,
            'is_active' => true,
        ]);

        return response()->json($integration, 201);
    }

    /**
     * Update an API integration
     */
    public function update(Request $request, ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        // Verify integration belongs to tenant
        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:rest,graphql,soap,webhook',
            'base_url' => 'sometimes|url|max:500',
            'auth_type' => 'sometimes|in:none,basic,bearer,api_key,oauth2',
            'auth_config' => 'nullable|array',
            'headers' => 'nullable|array',
            'timeout_seconds' => 'integer|min:1|max:300',
            'retry_attempts' => 'integer|min:0|max:10',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $integration->update($request->only([
            'name',
            'type',
            'base_url',
            'auth_type',
            'auth_config',
            'headers',
            'timeout_seconds',
            'retry_attempts',
            'is_active',
        ]));

        return response()->json($integration);
    }

    /**
     * Delete an API integration
     */
    public function destroy(ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        // Verify integration belongs to tenant
        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        $integration->delete();

        return response()->json([
            'message' => 'Integration deleted successfully',
        ]);
    }

    /**
     * Test an API integration
     */
    public function test(Request $request, ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        // Verify integration belongs to tenant
        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'endpoint' => 'required|string',
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'body' => 'nullable',
            'headers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $client = new Client([
                'timeout' => $integration->timeout_seconds,
                'http_errors' => false,
            ]);

            // Build URL
            $url = rtrim($integration->base_url, '/') . '/' . ltrim($request->endpoint, '/');
            // Build headers
            $headers = array_merge(
                (array) ($integration->headers ?? []),
                (array) ($request->headers ?? [])
            );

            // Add authentication
            $headers = $this->addAuthentication($headers, $integration);

            // Build request options
            $options = [
                'headers' => $headers,
            ];

            if (in_array($request->method, ['POST', 'PUT', 'PATCH'])) {
                if ($request->has('body')) {
                    $options['json'] = $request->body;
                }
            }

            // Make request
            $response = $client->request($request->method, $url, $options);

            return response()->json([
                'success' => true,
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => json_decode($response->getBody()->getContents(), true),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add authentication headers based on auth type
     */
    private function addAuthentication(array $headers, ApiIntegration $integration): array
    {
        $authConfig = $integration->auth_config ?? [];

        switch ($integration->auth_type) {
            case 'basic':
                if (isset($authConfig['username']) && isset($authConfig['password'])) {
                    $credentials = base64_encode($authConfig['username'] . ':' . $authConfig['password']);
                    $headers['Authorization'] = 'Basic ' . $credentials;
                }
                break;

            case 'bearer':
                if (isset($authConfig['token'])) {
                    $headers['Authorization'] = 'Bearer ' . $authConfig['token'];
                }
                break;

            case 'api_key':
                if (isset($authConfig['key']) && isset($authConfig['value'])) {
                    $headers[$authConfig['key']] = $authConfig['value'];
                }
                break;

            case 'oauth2':
                if (isset($authConfig['access_token'])) {
                    $headers['Authorization'] = 'Bearer ' . $authConfig['access_token'];
                }
                break;
        }

        return $headers;
    }
}
