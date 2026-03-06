<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Api as ApiModel;
use App\Models\Bot;
use App\Models\Tenant;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API integrations are now scoped to a Bot (not a Tenant).
 * The underlying model is App\Models\Api with a bot_id FK.
 */
class ApiIntegrationController extends Controller
{
    // GET /api/bots/{bot}/apis
    public function index(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $apis = ApiModel::where('bot_id', $bot->id)->orderBy('name')->get();

        return response()->json(['data' => $apis]);
    }

    // POST /api/bots/{bot}/apis
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'method'             => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'url'                => 'required|url|max:500',
            'content_type'       => 'nullable|string|max:100',
            'headers'            => 'nullable|array',
            'request_body'       => 'nullable|string',
            'form_data'          => 'nullable|array',
            'url_encoded_fields' => 'nullable|array',
            'body_parameters'    => 'nullable|array',
            'header_parameters'  => 'nullable|array',
            'is_active'          => 'nullable|boolean',
        ]);

        $api = ApiModel::create(array_merge($validated, [
            'bot_id'    => $bot->id,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        return response()->json(['message' => 'API created.', 'data' => $api], 201);
    }

    // GET /api/bots/{bot}/apis/{api}
    public function show(Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);

        return response()->json(['data' => $api]);
    }

    // PUT /api/bots/{bot}/apis/{api}
    public function update(Request $request, Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);

        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'method'             => 'sometimes|in:GET,POST,PUT,PATCH,DELETE',
            'url'                => 'sometimes|url|max:500',
            'content_type'       => 'nullable|string|max:100',
            'headers'            => 'nullable|array',
            'request_body'       => 'nullable|string',
            'form_data'          => 'nullable|array',
            'url_encoded_fields' => 'nullable|array',
            'body_parameters'    => 'nullable|array',
            'header_parameters'  => 'nullable|array',
            'is_active'          => 'sometimes|boolean',
        ]);

        $api->update($validated);

        return response()->json(['message' => 'API updated.', 'data' => $api]);
    }

    // DELETE /api/bots/{bot}/apis/{api}
    public function destroy(Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);

        $api->delete();

        return response()->json(['message' => 'API deleted.']);
    }

    // POST /api/bots/{bot}/apis/{api}/test
    // Fire a real HTTP request using the stored config to verify it works.
    public function test(Request $request, Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);

        $validated = $request->validate([
            'variables' => 'nullable|array',   // key-value pairs to interpolate into URL / body
        ]);

        try {
            $client = new Client(['timeout' => 15, 'http_errors' => false]);

            // Simple variable interpolation: {{varName}} → value
            $variables = $validated['variables'] ?? [];
            $url       = $this->interpolate($api->url, $variables);

            $options = ['headers' => array_merge($api->headers ?? [], ['Content-Type' => $api->content_type ?? 'application/json'])];

            if (in_array($api->method, ['POST', 'PUT', 'PATCH'], true) && $api->request_body) {
                $options['body'] = $this->interpolate($api->request_body, $variables);
            }

            $response = $client->request($api->method, $url, $options);

            return response()->json([
                'success' => true,
                'status'  => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body'    => json_decode($response->getBody()->getContents(), true),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) abort(404, 'Bot not found.');
    }

    private function authorizeApi(Bot $bot, ApiModel $api): void
    {
        if ($api->bot_id !== $bot->id) abort(404, 'API not found.');
    }

    private function interpolate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace("{{$key}}", $value, $template);
        }
        return $template;
    }
}
