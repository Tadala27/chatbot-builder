<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiIntegration;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use GuzzleHttp\Client;

class ApiIntegrationController extends Controller
{
    /**
     * List API integrations
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = ApiIntegration::where('tenant_id', $tenant->id);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $integrations = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return response()->json($integrations);
    }

    /**
     * Get single integration
     */
    public function show(ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        return response()->json(['integration' => $integration]);
    }

    /**
     * Create new integration
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:rest,graphql,soap,webhook',
            'base_url' => 'required|url|max:500',
            'auth_type' => 'required|in:none,basic,bearer,api_key,oauth2',
            'auth_config' => 'nullable|array',
            'headers' => 'nullable|array',
            'timeout_seconds' => 'integer|min:1|max:300',
            'retry_attempts' => 'integer|min:0|max:5',
        ]);

        $validated['tenant_id'] = $tenant->id;
        $validated['is_active'] = true;

        $integration = ApiIntegration::create($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($integration)
            ->log('API integration created');

        return response()->json([
            'message' => 'Integration created successfully',
            'integration' => $integration,
        ], 201);
    }

    /**
     * Update integration
     */
    public function update(Request $request, ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'base_url' => 'sometimes|url|max:500',
            'auth_type' => 'sometimes|in:none,basic,bearer,api_key,oauth2',
            'auth_config' => 'nullable|array',
            'headers' => 'nullable|array',
            'timeout_seconds' => 'sometimes|integer|min:1|max:300',
            'retry_attempts' => 'sometimes|integer|min:0|max:5',
            'is_active' => 'sometimes|boolean',
        ]);

        $integration->update($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($integration)
            ->log('API integration updated');

        return response()->json([
            'message' => 'Integration updated successfully',
            'integration' => $integration,
        ]);
    }

    /**
     * Delete integration
     */
    public function destroy(ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        $integration->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('API integration deleted: ' . $integration->name);

        return response()->json(['message' => 'Integration deleted successfully']);
    }

    /**
     * Test integration connection
     */
    public function test(Request $request, ApiIntegration $integration): JsonResponse
    {
        $tenant = Tenant::current();

        if ($integration->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Integration not found'], 404);
        }

        $validated = $request->validate([
            'endpoint' => 'required|string',
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'body' => 'nullable|array',
        ]);

        try {
            $client = new Client([
                'timeout' => $integration->timeout_seconds,
                'http_errors' => false,
            ]);

            $url = $integration->buildUrl($validated['endpoint']);
            $options = [
                'headers' => array_merge(
                    $integration->headers ?? [],
                    $integration->getAuthHeaders()
                ),
            ];

            if (in_array($validated['method'], ['POST', 'PUT', 'PATCH'])) {
                $options['json'] = $validated['body'] ?? [];
            }

            $startTime = microtime(true);
            $response = $client->request($validated['method'], $url, $options);
            $duration = (microtime(true) - $startTime) * 1000;

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            return response()->json([
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status_code' => $statusCode,
                'response' => json_decode($body, true) ?? $body,
                'duration_ms' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}