<?php

namespace App\Services;

class VariableResolver
{
    /**
     * Resolve variables in a string
     * Supports: $variable, ${variable}, $function(params)
     */
    public function resolve(string $text, array $variables): string
    {
        // Replace simple variables ($variable)
        $text = preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', function ($matches) use ($variables) {
            $varName = $matches[1];
            return $variables[$varName] ?? $matches[0];
        }, $text);

        // Replace braced variables (${variable})
        $text = preg_replace_callback('/\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($matches) use ($variables) {
            $varName = $matches[1];
            return $variables[$varName] ?? $matches[0];
        }, $text);

        // Replace function calls ($function(param1, param2))
        $text = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)\((.*?)\)/',
            function ($matches) use ($variables) {
                $functionName = $matches[1];
                $paramsString = $matches[2];

                // Parse parameters
                $params = $this->parseParameters($paramsString, $variables);

                // Execute built-in function
                return $this->executeBuiltInFunction($functionName, $params);
            },
            $text
        );

        return $text;
    }

    /**
     * Resolve variables in an array recursively
     */
    public function resolveArray(array $data, array $variables): array
    {
        $resolved = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $resolved[$key] = $this->resolveArray($value, $variables);
            } elseif (is_string($value)) {
                $resolved[$key] = $this->resolve($value, $variables);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Parse function parameters
     */
    private function parseParameters(string $paramsString, array $variables): array
    {
        if (empty(trim($paramsString))) {
            return [];
        }

        // Split by comma, but respect quotes
        $params = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;

        for ($i = 0; $i < strlen($paramsString); $i++) {
            $char = $paramsString[$i];

            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                continue;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = null;
                continue;
            } elseif ($char === ',' && !$inQuotes) {
                $params[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (!empty($current)) {
            $params[] = trim($current);
        }

        // Resolve each parameter
        return array_map(function ($param) use ($variables) {
            // Check if it's a variable reference
            if (str_starts_with($param, '$')) {
                $varName = substr($param, 1);
                return $variables[$varName] ?? $param;
            }
            return $param;
        }, $params);
    }

    /**
     * Execute built-in functions
     */
    private function executeBuiltInFunction(string $name, array $params)
    {
        return match ($name) {
            // Date/Time functions
            'now' => now()->toDateTimeString(),
            'today' => now()->toDateString(),
            'date' => isset($params[0]) ? \Carbon\Carbon::parse($params[0])->format($params[1] ?? 'Y-m-d') : '',
            'addDays' => isset($params[0], $params[1]) ? \Carbon\Carbon::parse($params[0])->addDays((int)$params[1])->toDateString() : '',
            'subtractDays' => isset($params[0], $params[1]) ? \Carbon\Carbon::parse($params[0])->subDays((int)$params[1])->toDateString() : '',
            'dayOfWeek' => isset($params[0]) ? \Carbon\Carbon::parse($params[0])->format('l') : '',
            'formatDate' => isset($params[0]) ? \Carbon\Carbon::parse($params[0])->format($params[1] ?? 'F j, Y') : '',

            // String functions
            'upper' => isset($params[0]) ? strtoupper($params[0]) : '',
            'lower' => isset($params[0]) ? strtolower($params[0]) : '',
            'capitalize' => isset($params[0]) ? ucfirst($params[0]) : '',
            'titleCase' => isset($params[0]) ? ucwords($params[0]) : '',
            'trim' => isset($params[0]) ? trim($params[0]) : '',
            'length' => isset($params[0]) ? strlen($params[0]) : 0,
            'substring' => isset($params[0], $params[1]) ? substr($params[0], (int)$params[1], isset($params[2]) ? (int)$params[2] : null) : '',
            'replace' => isset($params[0], $params[1], $params[2]) ? str_replace($params[1], $params[2], $params[0]) : '',
            'split' => isset($params[0], $params[1]) ? explode($params[1], $params[0]) : [],
            'join' => isset($params[0], $params[1]) ? (is_array($params[0]) ? implode($params[1], $params[0]) : '') : '',
            'contains' => isset($params[0], $params[1]) ? (str_contains($params[0], $params[1]) ? 'true' : 'false') : 'false',
            'startsWith' => isset($params[0], $params[1]) ? (str_starts_with($params[0], $params[1]) ? 'true' : 'false') : 'false',
            'endsWith' => isset($params[0], $params[1]) ? (str_ends_with($params[0], $params[1]) ? 'true' : 'false') : 'false',

            // Formatting functions
            'formatNumber' => isset($params[0]) ? number_format((float)$params[0], $params[1] ?? 2) : '',
            'formatCurrency' => isset($params[0]) ? '$' . number_format((float)$params[0], 2) : '',
            'formatPhone' => isset($params[0]) ? $this->formatPhone($params[0]) : '',
            'formatPercentage' => isset($params[0]) ? round((float)$params[0] * 100, 2) . '%' : '',

            // Logical functions
            'if' => isset($params[0], $params[1], $params[2]) ? ($this->isTruthy($params[0]) ? $params[1] : $params[2]) : '',
            'isEmpty' => isset($params[0]) ? (empty($params[0]) ? 'true' : 'false') : 'true',
            'isNotEmpty' => isset($params[0]) ? (!empty($params[0]) ? 'true' : 'false') : 'false',
            'and' => $this->evaluateAnd($params),
            'or' => $this->evaluateOr($params),
            'not' => isset($params[0]) ? (!$this->isTruthy($params[0]) ? 'true' : 'false') : 'true',

            // Math functions
            'add' => isset($params[0], $params[1]) ? ((float)$params[0] + (float)$params[1]) : 0,
            'subtract' => isset($params[0], $params[1]) ? ((float)$params[0] - (float)$params[1]) : 0,
            'multiply' => isset($params[0], $params[1]) ? ((float)$params[0] * (float)$params[1]) : 0,
            'divide' => isset($params[0], $params[1]) && $params[1] != 0 ? ((float)$params[0] / (float)$params[1]) : 0,
            'round' => isset($params[0]) ? round((float)$params[0], $params[1] ?? 0) : 0,
            'floor' => isset($params[0]) ? floor((float)$params[0]) : 0,
            'ceil' => isset($params[0]) ? ceil((float)$params[0]) : 0,
            'min' => !empty($params) ? min(array_map('floatval', $params)) : 0,
            'max' => !empty($params) ? max(array_map('floatval', $params)) : 0,
            'random' => isset($params[0], $params[1]) ? rand((int)$params[0], (int)$params[1]) : 0,

            // Array functions
            'arrayLength' => isset($params[0]) && is_array($params[0]) ? count($params[0]) : 0,
            'first' => isset($params[0]) && is_array($params[0]) && !empty($params[0]) ? reset($params[0]) : null,
            'last' => isset($params[0]) && is_array($params[0]) && !empty($params[0]) ? end($params[0]) : null,
            'indexOf' => isset($params[0], $params[1]) && is_array($params[0]) ? array_search($params[1], $params[0]) : -1,

            default => "UNKNOWN_FUNCTION({$name})",
        };
    }

    /**
     * Format phone number
     */
    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6)
            );
        }

        return $phone;
    }

    /**
     * Check if value is truthy
     */
    private function isTruthy($value): bool
    {
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return !in_array($lower, ['', '0', 'false', 'no', 'null']);
        }

        return (bool) $value;
    }

    /**
     * Evaluate AND operation
     */
    private function evaluateAnd(array $params): string
    {
        foreach ($params as $param) {
            if (!$this->isTruthy($param)) {
                return 'false';
            }
        }
        return 'true';
    }

    /**
     * Evaluate OR operation
     */
    private function evaluateOr(array $params): string
    {
        foreach ($params as $param) {
            if ($this->isTruthy($param)) {
                return 'true';
            }
        }
        return 'false';
    }

    /**
     * Resolve value (handle variable references)
     */
    public function resolveValue($value, array $variables)
    {
        if (is_string($value) && str_starts_with($value, '$')) {
            $varName = substr($value, 1);
            return $variables[$varName] ?? null;
        }

        return $value;
    }
}