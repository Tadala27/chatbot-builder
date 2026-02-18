<?php

namespace App\Services;

use App\Models\CustomFunction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\ProcessFailedException;

class FunctionExecutor
{
    /**
     * Execute a custom function
     */
    public function execute(int $functionId, array $parameters)
    {
        $function = Cache::remember(
            "function_{$functionId}",
            3600,
            fn() => CustomFunction::find($functionId)
        );

        if (!$function) {
            throw new \Exception("Function not found: {$functionId}");
        }

        if (!$function->is_active) {
            throw new \Exception("Function is not active: {$function->name}");
        }

        Log::info('Executing custom function', [
            'function_id' => $functionId,
            'function_name' => $function->name,
            'function_type' => $function->function_type,
            'parameters' => $parameters,
        ]);

        try {
            $result = match ($function->function_type) {
                'javascript' => $this->executeJavaScript($function, $parameters),
                'api_call' => $this->executeApiCall($function, $parameters),
                'webhook' => $this->executeWebhook($function, $parameters),
                'built_in' => $this->executeBuiltIn($function, $parameters),
                default => throw new \Exception('Unknown function type: ' . $function->function_type),
            };

            Log::info('Function executed successfully', [
                'function_id' => $functionId,
                'result_type' => gettype($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Function execution failed', [
                'function_id' => $functionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Execute JavaScript function using Node.js subprocess
     */
    private function executeJavaScript(CustomFunction $function, array $parameters)
    {
        // Build a safe, temporary JS script that:
        // - Receives parameters as JSON
        // - Runs the user code
        // - Prints result as JSON (or error)
        $userCode = $function->code;

        // Basic security: block dangerous globals/patterns (you can expand this)
        $this->validateJavaScriptCode($userCode);

        // Create a self-contained script
        $script = <<<JS
const params = JSON.parse(process.argv[2]);

// --- User-supplied code starts here ---
{$userCode}
// If the code doesn't explicitly return, assume last expression or use a function
let result;
try {
    // If it's a function expression/block, try to call it with params
    if (typeof main === 'function') {
        result = main(params);
    } else {
        // Otherwise assume the code itself computes something and assigns to 'result'
        // or just eval the last part – adjust based on your convention
        result = eval('(' + {$userCode} + ')(params)');  // risky – see notes below
    }
} catch (e) {
    console.error(e.message);
    process.exit(1);
}

console.log(JSON.stringify({ result }));
JS;

        // Better/safer pattern: require user code to define a function called `execute(params)`
        // Strongly recommend enforcing this in your UI/editor + validateSyntax()
        //
        // Alternative safer version (recommended):
        // $script = <<<JS
        // const params = JSON.parse(process.argv[2]);
        // {$userCode}
        // let output = execute(params);   // must define function execute(params) { ... }
        // console.log(JSON.stringify({ result: output }));
        // JS;

        $jsonParams = json_encode($parameters);

        $process = new Process([
            'node',
            '--max-old-space-size=128',     // ~128 MB heap limit (adjust as needed)
            // '--no-warnings',              // optional
            // Add --frozen-intrinsics or other flags for extra hardening if desired
        ]);

        $process->setInput($script);         // or write script to temp file if very large
        // Better for security/large code: write to temp file
        // $tempFile = tempnam(sys_get_temp_dir(), 'js_exec_');
        // file_put_contents($tempFile, $script);
        // $process = new Process(['node', '--max-old-space-size=128', $tempFile, $jsonParams]);

        $process->setTimeout($function->timeout_seconds ?: 10);      // enforce your timeout
        $process->setIdleTimeout(8);                                 // no output for X sec → kill

        try {
            $process->mustRun(null, ['NODE_NO_WARNINGS' => '1']);

            $output = $process->getOutput();
            $decoded = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON from Node: ' . $output);
            }

            if (isset($decoded['result'])) {
                return $decoded['result'];
            }

            throw new \Exception('No result returned from JavaScript');
        } catch (ProcessTimedOutException $e) {
            throw new \Exception('Function execution timeout');
        } catch (ProcessFailedException $e) {
            $errorOutput = $process->getErrorOutput();
            throw new \Exception('JavaScript execution error: ' . trim($errorOutput ?: $e->getMessage()));
        } catch (\Exception $e) {
            throw new \Exception('JavaScript execution failed: ' . $e->getMessage());
        } finally {
            // if using temp file: @unlink($tempFile);
        }
    }
    /**
     * Execute API call function
     */
    private function executeApiCall(CustomFunction $function, array $parameters)
    {
        $config = json_decode($function->code, true);

        if (!$config || !isset($config['url'])) {
            throw new \Exception('Invalid API call configuration');
        }

        $client = new \GuzzleHttp\Client([
            'timeout' => $function->timeout_seconds,
            'http_errors' => false,
        ]);

        try {
            $method = $config['method'] ?? 'GET';
            $url = $config['url'];

            // Replace parameters in URL
            foreach ($parameters as $key => $value) {
                $url = str_replace('{' . $key . '}', $value, $url);
            }

            $options = [
                'headers' => $config['headers'] ?? [],
            ];

            // Add body for POST/PUT/PATCH
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $body = $config['body'] ?? [];

                // Merge with parameters
                $body = array_merge($body, $parameters);

                $options['json'] = $body;
            } else {
                // Add query params for GET
                $options['query'] = $parameters;
            }

            $response = $client->request($method, $url, $options);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            // Try to decode JSON
            $data = json_decode($responseBody, true);

            if ($statusCode >= 400) {
                throw new \Exception("API returned error: {$statusCode}");
            }

            return $data ?? $responseBody;
        } catch (\Exception $e) {
            throw new \Exception('API call failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute webhook function
     */
    private function executeWebhook(CustomFunction $function, array $parameters)
    {
        $config = json_decode($function->code, true);

        if (!$config || !isset($config['url'])) {
            throw new \Exception('Invalid webhook configuration');
        }

        $client = new \GuzzleHttp\Client([
            'timeout' => $function->timeout_seconds,
            'http_errors' => false,
        ]);

        try {
            $response = $client->post($config['url'], [
                'json' => $parameters,
                'headers' => $config['headers'] ?? [],
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            if ($statusCode >= 400) {
                throw new \Exception("Webhook returned error: {$statusCode}");
            }

            $data = json_decode($responseBody, true);
            return $data ?? $responseBody;
        } catch (\Exception $e) {
            throw new \Exception('Webhook call failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute built-in function
     */
    private function executeBuiltIn(CustomFunction $function, array $parameters)
    {
        // Built-in functions can be system-defined utilities
        // For now, we'll use the VariableResolver for built-in functions

        $resolver = app(VariableResolver::class);

        // Build function call string
        $paramStrings = array_map(function ($value) {
            if (is_string($value)) {
                return "'{$value}'";
            }
            return $value;
        }, $parameters);

        $functionCall = '$' . $function->slug . '(' . implode(', ', $paramStrings) . ')';

        return $resolver->resolve($functionCall, []);
    }

    /**
     * Test function execution
     */
    public function test(CustomFunction $function, array $testParameters = []): array
    {
        $startTime = microtime(true);

        try {
            $result = $this->execute($function->id, $testParameters);

            $executionTime = (microtime(true) - $startTime) * 1000; // milliseconds

            return [
                'success' => true,
                'result' => $result,
                'execution_time_ms' => round($executionTime, 2),
            ];
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => round($executionTime, 2),
            ];
        }
    }

    /**
     * Validate function code syntax
     */
    public function validateSyntax(string $code, string $type): array
    {
        $errors = [];

        switch ($type) {
            case 'javascript':
                // Basic JavaScript syntax checks
                if (stripos($code, 'eval(') !== false) {
                    $errors[] = 'eval() is not allowed for security reasons';
                }
                if (stripos($code, 'require(') !== false || stripos($code, 'import ') !== false) {
                    $errors[] = 'require() and import are not allowed';
                }
                break;

            case 'api_call':
            case 'webhook':
                $config = json_decode($code, true);
                if (!$config) {
                    $errors[] = 'Invalid JSON configuration';
                } elseif (!isset($config['url'])) {
                    $errors[] = 'URL is required';
                } elseif (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
                    $errors[] = 'Invalid URL format';
                }
                break;
        }

        return $errors;
    }
}