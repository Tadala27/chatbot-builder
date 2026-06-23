<?php

namespace App\Services\Bot;

use App\Models\CustomFunction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;


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
            'function_id'   => $functionId,
            'function_name' => $function->name,
            'function_type' => $function->function_type,
            'parameters'    => $parameters,
        ]);

        try {
            $result = match ($function->function_type) {
                'javascript' => $this->executeJavaScript($function, $parameters),
                'api_call'   => $this->executeApiCall($function, $parameters),
                'webhook'    => $this->executeWebhook($function, $parameters),
                'built_in'   => $this->executeBuiltIn($function, $parameters),
                default      => throw new \Exception('Unknown function type: ' . $function->function_type),
            };

            Log::info('Function executed successfully', [
                'function_id' => $functionId,
                'result_type' => gettype($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Function execution failed', [
                'function_id' => $functionId,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

        /**
     * Execute JavaScript function using Node.js subprocess
     */
    private function executeJavaScript(\App\Models\CustomFunction $function, array $parameters)
    {
        $userCode = $function->code;
    
            // 1. Pattern checks (cheap defense; not a substitute for isolation)
        $this->validateJavaScriptCode($userCode);
    
            // 2. Build the runner script. User code gets its own file — no string
            //    interpolation means no injection risk.
        $runnerScript = <<<'JS'
            'use strict';
            
                // Block obviously dangerous globals inside the sandbox.
                // (Defense in depth — the real isolation is the OS container.)
            const blockedGlobals = ['require', 'process', 'Buffer', 'global', 'globalThis'];
            for (const name of blockedGlobals) {
                try {
                    Object.defineProperty(globalThis, name, {
                        get() { throw new Error(`Access to ${name} is forbidden`); },
                        configurable: false,
                    });
                } catch (e) { /* already locked */ }
            }
            
                // Parse params passed via argv
            let params;
            try {
                params = JSON.parse(__USER_PARAMS__);
            } catch (e) {
                console.error('Invalid params JSON: ' + e.message);
                globalThis.__exitCode = 2;
            }
            
                // Load user code — it MUST define a function called `execute(params)`
            __USER_CODE__;
            
                // Invoke
            (async () => {
                try {
                    if (typeof execute !== 'function') {
                        throw new Error('User code must define a function: execute(params)');
                    }
                    const result = await execute(params);
                        // stdout is the only return channel
                    const payload = JSON.stringify({ result: result ?? null });
                    if (payload.length > 5 * 1024 * 1024) {
                        throw new Error('Result exceeds 5MB limit');
                    }
                    console.log(payload);
                } catch (err) {
                    console.error(err && err.message ? err.message : String(err));
                    globalThis.__exitCode = 1;
                }
            })().then(() => {
                if (globalThis.__exitCode) process.exit(globalThis.__exitCode);
            });
        JS;
    
            // 3. Write the runner + user code to a temp file.
            //    We use placeholder substitution (not string interpolation) so the user
            //    code can contain any JavaScript special characters without breaking
            //    our runner.
        $jsonParams = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
        $fullScript = str_replace(
            ['__USER_CODE__', '__USER_PARAMS__'],
            [$userCode, var_export($jsonParams, true)],
            $runnerScript
        );
    
        $tempFile = tempnam(sys_get_temp_dir(), 'js_exec_');
        if ($tempFile === false) {
            throw new \RuntimeException('Could not create temp file for JS execution');
        }
    
        try {
            file_put_contents($tempFile, $fullScript);
    
                // 4. Run Node with hardening flags
            $process = new \Symfony\Component\Process\Process([
                'node',
                '--max-old-space-size=128',  // 128 MB heap ceiling
                '--no-warnings',
                $tempFile,
            ], null, [
                'NODE_NO_WARNINGS' => '1',
                'NODE_ENV'         => 'production',
                    // Strip PATH so shell-out via child_process is harder
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
            ]);
    
            $process->setTimeout($function->timeout_seconds ?: 10);
            $process->setIdleTimeout(8);
    
            try {
                $process->mustRun();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                throw new \Exception('Function execution timeout');
            } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) {
                $errorOutput = trim($process->getErrorOutput() ?: $e->getMessage());
                throw new \Exception('JavaScript execution error: ' . $errorOutput);
            }
    
            $output  = $process->getOutput();
            $decoded = json_decode($output, true);
    
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON from Node: ' . substr($output, 0, 200));
            }
    
            if (!array_key_exists('result', $decoded)) {
                throw new \Exception('Runner did not return a result');
            }
    
            return $decoded['result'];
        } finally {
            @unlink($tempFile);
        }
    }
 
    /**
     * Cheap pattern checks for obvious abuse. This is NOT a security boundary —
     * the real boundary is process isolation / containerization. But it catches
     * lazy attackers and honest mistakes.
     */
    private function validateJavaScriptCode(string $code): void
    {
        $forbiddenPatterns = [
            '/\beval\s*\(/'        => 'eval() is forbidden',
            '/\brequire\s*\(/'     => 'require() is forbidden',
            '/\bimport\s+/'        => 'import is forbidden',
            '/\bimport\s*\(/'      => 'dynamic import is forbidden',
            '/\bchild_process\b/'  => 'child_process is forbidden',
            '/\bprocess\s*\./'     => 'process access is forbidden',
            '/\bglobal\s*\[/'      => 'global access is forbidden',
            '/\bFunction\s*\(/'    => 'Function constructor is forbidden',
            '/\b__proto__\b/'      => '__proto__ is forbidden',
            '/\bconstructor\s*\[/' => 'constructor indexing is forbidden',
        ];
    
        foreach ($forbiddenPatterns as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                throw new \Exception("JavaScript validation failed: {$reason}");
            }
        }
    
            // Require the `execute` function contract
        if (!preg_match('/\bfunction\s+execute\s*\(/', $code) &&
            !preg_match('/\bexecute\s*=\s*(async\s+)?(function|\()/', $code) &&
            !preg_match('/\bconst\s+execute\s*=/', $code)) {
            throw new \Exception(
                'JavaScript code must define a function named "execute(params)". ' .
                'Example: function execute(params) { return params.a + params.b; }'
            );
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
            'timeout'     => $function->timeout_seconds,
            'http_errors' => false,
        ]);

        try {
            $method = $config['method'] ?? 'GET';
            $url    = $config['url'];

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

            $statusCode   = $response->getStatusCode();
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
            'timeout'     => $function->timeout_seconds,
            'http_errors' => false,
        ]);

        try {
            $response = $client->post($config['url'], [
                'json'    => $parameters,
                'headers' => $config['headers'] ?? [],
            ]);

            $statusCode   = $response->getStatusCode();
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

            $executionTime = (microtime(true) - $startTime) * 1000;  // milliseconds

            return [
                'success'           => true,
                'result'            => $result,
                'execution_time_ms' => round($executionTime, 2),
            ];
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            return [
                'success'           => false,
                'error'             => $e->getMessage(),
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
            case 'webhook' : 
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