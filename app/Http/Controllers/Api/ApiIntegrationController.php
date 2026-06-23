<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Api as ApiModel;
use App\Models\Bot;
use App\Models\Tenant;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;


class ApiIntegrationController extends Controller
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function index(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);
        $apis = ApiModel::where('bot_id', $bot->id)->orderBy('name')->get()
            ->map(fn($api) => $this->sanitizeForOutput($api));
        return response()->json(['data' => $apis]);
    }

    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);
        $validated = $this->validateApiPayload($request);

        $api = ApiModel::create(array_merge($validated, [
            'bot_id'      => $bot->id,
            'auth_config' => $this->encryptAuthConfig($validated['auth_config'] ?? null),
            'is_active'   => $validated['is_active'] ?? true,
        ]));

        return response()->json(['message' => 'API created.', 'data' => $this->sanitizeForOutput($api)], 201);
    }

    public function show(Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);
        return response()->json(['data' => $this->sanitizeForOutput($api)]);
    }

    public function update(Request $request, Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);
        $validated = $this->validateApiPayload($request, partial: true);

        if (array_key_exists('auth_config', $validated)) {
            $validated['auth_config'] = $this->encryptAuthConfig($validated['auth_config']);
        }

        $api->update($validated);
        return response()->json(['message' => 'API updated.', 'data' => $this->sanitizeForOutput($api->fresh())]);
    }

    public function destroy(Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);
        $api->delete();
        return response()->json(['message' => 'API deleted.']);
    }

    // ── Test a saved API ──────────────────────────────────────────────────────

    public function test(Request $request, Bot $bot, ApiModel $api): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeApi($bot, $api);

        $variables = $request->validate(['variables' => 'nullable|array'])['variables'] ?? [];

        // Decrypt auth_config for execution
        $authConfig = $this->decryptAuthConfig($api->auth_config);

        return $this->executeRequest(
            method: $api->method,
            url: $this->interpolate($api->url, $variables),
            contentType: $api->content_type ?? 'application/json',
            headers: $api->headers ?? [],
            authType: $api->auth_type ?? 'none',
            authConfig: $authConfig ?? [],
            requestBody: $api->request_body,
            formData: $api->form_data ?? [],
            urlEncoded: $api->url_encoded_fields ?? [],
            timeout: $api->timeout_seconds ?? 30,
            variables: $variables,
        );
    }

    // ── Test a draft (not yet saved) ──────────────────────────────────────────

    public function testDraft(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $data = $request->validate([
            'method'             => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'url'                => 'required|string',
            'content_type'       => 'nullable|string',
            'headers'            => 'nullable|array',
            'auth_type'          => 'nullable|string',
            'auth_config'        => 'nullable|array',
            'request_body'       => 'nullable|string',
            'form_data'          => 'nullable|array',
            'url_encoded_fields' => 'nullable|array',
            'timeout_seconds'    => 'nullable|integer|min:1|max:300',
            'variables'          => 'nullable|array',
        ]);

        return $this->executeRequest(
            method: $data['method'],
            url: $this->interpolate($data['url'], $data['variables'] ?? []),
            contentType: $data['content_type'] ?? 'application/json',
            headers: $data['headers'] ?? [],
            authType: $data['auth_type'] ?? 'none',
            authConfig: $data['auth_config'] ?? [],
            requestBody: $data['request_body'] ?? null,
            formData: $data['form_data'] ?? [],
            urlEncoded: $data['url_encoded_fields'] ?? [],
            timeout: $data['timeout_seconds'] ?? 30,
            variables: $data['variables'] ?? [],
        );
    }

    // ── Core HTTP execution ───────────────────────────────────────────────────

    private function executeRequest(
        string  $method,
        string  $url,
        string  $contentType,
        array   $headers,
        string  $authType,
        array   $authConfig,
        ?string $requestBody,
        array   $formData,
        array   $urlEncoded,
        int     $timeout,
        array   $variables,
    ): JsonResponse {
        try {
            $client  = new Client(['timeout' => $timeout, 'http_errors' => false]);
            $options = ['headers' => []];

            // ── Build headers from stored config ──────────────────────────────
            foreach ($headers as $h) {
                if (!empty($h['key'])) {
                    $options['headers'][$h['key']] = $this->interpolate($h['value'] ?? '', $variables);
                }
            }

            // ── Apply authentication ───────────────────────────────────────────
            match ($authType) {
                'basic'   => $options['auth'] = [
                    $authConfig['username'] ?? '',
                    $authConfig['password'] ?? '',
                ],
                'bearer'  => $options['headers']['Authorization'] = 'Bearer ' . ($authConfig['token'] ?? ''),
                'api_key' => $options['headers'][$authConfig['key'] ?? 'X-API-Key'] = $authConfig['value'] ?? '',
                'oauth2'  => $options['headers']['Authorization'] = 'Bearer ' . ($authConfig['access_token'] ?? ''),
                default   => null,
            };

            // ── Build request body ────────────────────────────────────────────
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                if ($contentType === 'application/json') {
                    $options['headers']['Content-Type'] = 'application/json';
                    $options['body'] = $this->interpolate($requestBody ?? '{}', $variables);
                } elseif ($contentType === 'multipart/form-data') {
                    $multipart = [];
                    foreach ($formData as $field) {
                        if (!empty($field['key'])) {
                            $multipart[] = [
                                'name'     => $field['key'],
                                'contents' => $this->interpolate((string)($field['value'] ?? ''), $variables),
                            ];
                        }
                    }
                    $options['multipart'] = $multipart;
                } elseif ($contentType === 'application/x-www-form-urlencoded') {
                    $form = [];
                    foreach ($urlEncoded as $field) {
                        if (!empty($field['key'])) {
                            $form[$field['key']] = $this->interpolate((string)($field['value'] ?? ''), $variables);
                        }
                    }
                    $options['form_params'] = $form;
                }
            }

            $response     = $client->request($method, $url, $options);
            $responseBody = $response->getBody()->getContents();
            $decoded      = json_decode($responseBody, true);

            return response()->json([
                'success'    => true,
                'status'     => $response->getStatusCode(),
                'statusText' => $response->getReasonPhrase(),
                'headers'    => $response->getHeaders(),
                'body'       => $decoded ?? $responseBody,
                'raw'        => $responseBody,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success'    => false,
                'status'     => 0,
                'statusText' => 'Request failed',
                'error'      => $e->getMessage(),
                'body'       => null,
                'headers'    => [],
            ], 422);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateApiPayload(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name'               => "{$rule}|string|max:255",
            'description'        => 'nullable|string|max:1000',
            'method'             => "{$rule}|in:GET,POST,PUT,PATCH,DELETE",
            'url'                => "{$rule}|string|max:500",
            'content_type'       => 'nullable|string|max:100',
            'auth_type'          => 'nullable|in:none,basic,bearer,api_key,oauth2',
            'auth_config'        => 'nullable|array',
            'headers'            => 'nullable|array',
            'request_body'       => 'nullable|string',
            'form_data'          => 'nullable|array',
            'url_encoded_fields' => 'nullable|array',
            'body_parameters'    => 'nullable|array',
            'header_parameters'  => 'nullable|array',
            'variable_mappings'  => 'nullable|array',
            'variable_mappings.*.response_path' => 'required_with:variable_mappings|string',
            'variable_mappings.*.variable'      => 'required_with:variable_mappings|string',
            'timeout_seconds'    => 'nullable|integer|min:1|max:300',
            'retry_attempts'     => 'nullable|integer|min:0|max:10',
            'is_active'          => 'nullable|boolean',
        ]);
    }

    private function encryptAuthConfig(?array $config): ?string
    {
        if (empty($config)) return null;
        return Crypt::encryptString(json_encode($config));
    }

    private function decryptAuthConfig(?string $encrypted): ?array
    {
        if (!$encrypted) return null;
        try {
            return json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Exception) {
            return null;
        }
    }

    /** Strip auth_config from API output — never send credentials to frontend. */
    private function sanitizeForOutput(ApiModel $api): array
    {
        $data = $api->toArray();
        unset($data['auth_config']);
        // Tell the frontend which auth type is configured without exposing values
        $data['has_auth'] = !empty($api->auth_config);
        return $data;
    }

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
            $template = str_replace("{{{$key}}}", $value, $template); // also handle {{var}}
        }
        return $template;
    }
}