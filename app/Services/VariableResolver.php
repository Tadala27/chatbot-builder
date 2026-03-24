<?php

namespace App\Services;

class VariableResolver
{

    public function resolve(string $text, array $variables): string
    {

        $text = preg_replace_callback(
            '/f\(x\)\s+([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($matches) use ($variables) {
                $functionName = $matches[1];
                return (string) $this->executeBuiltInFunction($functionName, []);
            },
            $text
        );

        $text = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)\((.*?)\)/s',
            function ($matches) use ($variables) {
                $functionName = $matches[1];
                $paramsString = $matches[2];
                $params       = $this->parseParameters($paramsString, $variables);
                return (string) $this->executeBuiltInFunction($functionName, $params);
            },
            $text
        );

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            function ($matches) use ($variables) {
                return $variables[$matches[1]] ?? $matches[0];
            },
            $text
        );

        $text = preg_replace_callback(
            '/\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($matches) use ($variables) {
                return $variables[$matches[1]] ?? $matches[0];
            },
            $text
        );


        $text = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($matches) use ($variables) {
                return $variables[$matches[1]] ?? $matches[0];
            },
            $text
        );

        return $text;
    }

    /**
     * Resolve variables in an array recursively.
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

    public function resolveValue(mixed $value, array $variables): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // {{variable}} format
        if (preg_match('/^\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}$/', $value, $matches)) {
            return $variables[$matches[1]] ?? null;
        }

        // f(x) functionName — zero-arg built-in
        if (preg_match('/^f\(x\)\s+([a-zA-Z_][a-zA-Z0-9_]*)$/', $value, $matches)) {
            return $this->executeBuiltInFunction($matches[1], []);
        }

        // $functionName(params) — built-in with args
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)\((.*?)\)$/s', $value, $matches)) {
            $params = $this->parseParameters($matches[2], $variables);
            return $this->executeBuiltInFunction($matches[1], $params);
        }

        // $variable format
        if (str_starts_with($value, '$')) {
            $varName = substr($value, 1);
            return $variables[$varName] ?? null;
        }

        return $value;
    }


    private function parseParameters(string $paramsString, array $variables): array
    {
        if (empty(trim($paramsString))) {
            return [];
        }

        $params    = [];
        $current   = '';
        $inQuotes  = false;
        $quoteChar = null;

        for ($i = 0; $i < strlen($paramsString); $i++) {
            $char = $paramsString[$i];

            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes  = true;
                $quoteChar = $char;
                continue;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes  = false;
                $quoteChar = null;
                continue;
            } elseif ($char === ',' && !$inQuotes) {
                $params[] = trim($current);
                $current  = '';
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $params[] = trim($current);
        }

        // Resolve variable references within each parameter
        return array_map(function ($param) use ($variables) {
            // $variable reference
            if (str_starts_with($param, '$') && !str_contains($param, '(')) {
                $varName = substr($param, 1);
                return $variables[$varName] ?? $param;
            }
            // {{variable}} reference
            if (preg_match('/^\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}$/', $param, $matches)) {
                return $variables[$matches[1]] ?? $param;
            }
            return $param;
        }, $params);
    }


    private function executeBuiltInFunction(string $name, array $params): mixed
    {
        return match ($name) {
            // ── Date / Time ───────────────────────────────────────────────────
            'now'          => now()->toDateTimeString(),
            'today'        => now()->toDateString(),
            'date'         => isset($params[0])
                ? \Carbon\Carbon::parse($params[0])->format($params[1] ?? 'Y-m-d')
                : '',
            'addDays'      => isset($params[0], $params[1])
                ? \Carbon\Carbon::parse($params[0])->addDays((int) $params[1])->toDateString()
                : '',
            'subtractDays' => isset($params[0], $params[1])
                ? \Carbon\Carbon::parse($params[0])->subDays((int) $params[1])->toDateString()
                : '',
            'dayOfWeek'    => isset($params[0])
                ? \Carbon\Carbon::parse($params[0])->format('l')
                : '',
            'formatDate'   => isset($params[0])
                ? \Carbon\Carbon::parse($params[0])->format($params[1] ?? 'F j, Y')
                : '',

            // ── String ────────────────────────────────────────────────────────
            'upper'      => isset($params[0]) ? strtoupper($params[0])  : '',
            'lower'      => isset($params[0]) ? strtolower($params[0])  : '',
            'capitalize' => isset($params[0]) ? ucfirst($params[0])     : '',
            'titleCase'  => isset($params[0]) ? ucwords($params[0])     : '',
            'trim'       => isset($params[0]) ? trim($params[0])        : '',
            'length'     => isset($params[0]) ? strlen($params[0])      : 0,
            'substring'  => isset($params[0], $params[1])
                ? substr($params[0], (int) $params[1], isset($params[2]) ? (int) $params[2] : null)
                : '',
            'replace'    => isset($params[0], $params[1], $params[2])
                ? str_replace($params[1], $params[2], $params[0])
                : '',
            'split'      => isset($params[0], $params[1])
                ? explode($params[1], $params[0])
                : [],
            'join'       => isset($params[0], $params[1]) && is_array($params[0])
                ? implode($params[1], $params[0])
                : '',
            'contains'   => isset($params[0], $params[1])
                ? (str_contains($params[0], $params[1])    ? 'true' : 'false')
                : 'false',
            'startsWith' => isset($params[0], $params[1])
                ? (str_starts_with($params[0], $params[1]) ? 'true' : 'false')
                : 'false',
            'endsWith'   => isset($params[0], $params[1])
                ? (str_ends_with($params[0], $params[1])   ? 'true' : 'false')
                : 'false',

            // ── Formatting ────────────────────────────────────────────────────
            'formatNumber'     => isset($params[0])
                ? number_format((float) $params[0], (int) ($params[1] ?? 2))
                : '',
            'formatCurrency'   => isset($params[0])
                ? '$' . number_format((float) $params[0], 2)
                : '',
            'formatPhone'      => isset($params[0]) ? $this->formatPhone($params[0]) : '',
            'formatPercentage' => isset($params[0])
                ? round((float) $params[0] * 100, 2) . '%'
                : '',

            // ── Logical ───────────────────────────────────────────────────────
            'if'         => isset($params[0], $params[1], $params[2])
                ? ($this->isTruthy($params[0]) ? $params[1] : $params[2])
                : '',
            'isEmpty'    => isset($params[0]) ? (empty($params[0])   ? 'true' : 'false') : 'true',
            'isNotEmpty' => isset($params[0]) ? (!empty($params[0])  ? 'true' : 'false') : 'false',
            'and'        => $this->evaluateAnd($params),
            'or'         => $this->evaluateOr($params),
            'not'        => isset($params[0])
                ? (!$this->isTruthy($params[0]) ? 'true' : 'false')
                : 'true',

            // ── Math ──────────────────────────────────────────────────────────
            'add'      => isset($params[0], $params[1]) ? (float) $params[0] + (float) $params[1]  : 0,
            'subtract' => isset($params[0], $params[1]) ? (float) $params[0] - (float) $params[1]  : 0,
            'multiply' => isset($params[0], $params[1]) ? (float) $params[0] * (float) $params[1]  : 0,
            'divide'   => isset($params[0], $params[1]) && (float) $params[1] !== 0.0
                ? (float) $params[0] / (float) $params[1]
                : 0,
            'round'    => isset($params[0]) ? round((float) $params[0], (int) ($params[1] ?? 0)) : 0,
            'floor'    => isset($params[0]) ? floor((float) $params[0])  : 0,
            'ceil'     => isset($params[0]) ? ceil((float) $params[0])   : 0,
            'min'      => !empty($params) ? min(array_map('floatval', $params)) : 0,
            'max'      => !empty($params) ? max(array_map('floatval', $params)) : 0,
            'random'   => isset($params[0], $params[1])
                ? rand((int) $params[0], (int) $params[1])
                : 0,

            // ── Array ─────────────────────────────────────────────────────────
            'arrayLength' => isset($params[0]) && is_array($params[0]) ? count($params[0])              : 0,
            'first'       => isset($params[0]) && is_array($params[0]) && !empty($params[0])
                ? reset($params[0])
                : null,
            'last'        => isset($params[0]) && is_array($params[0]) && !empty($params[0])
                ? end($params[0])
                : null,
            'indexOf'     => isset($params[0], $params[1]) && is_array($params[0])
                ? array_search($params[1], $params[0])
                : -1,

            default => "UNKNOWN_FUNCTION({$name})",
        };
    }


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

    private function isTruthy(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'null'], true);
        }

        return (bool) $value;
    }

    private function evaluateAnd(array $params): string
    {
        foreach ($params as $param) {
            if (!$this->isTruthy($param)) {
                return 'false';
            }
        }
        return 'true';
    }

    private function evaluateOr(array $params): string
    {
        foreach ($params as $param) {
            if ($this->isTruthy($param)) {
                return 'true';
            }
        }
        return 'false';
    }
}