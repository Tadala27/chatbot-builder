<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\WhatsappAccount;

class VariableResolver
{
    /**
     * Get system variables for a conversation.
     * These are available to all variable resolutions.
     */
    public function systemVars(array $variables, string $to, WhatsappAccount $account): array
    {
        try {
            $conv = Conversation::where('whatsapp_account_id', $account->id)
                ->where('whatsapp_user_phone', $to)
                ->latest()
                ->first();
            $name = $conv?->whatsapp_user_name ?? '';
            $convId = $conv?->id;
            $botName = $conv?->bot?->name ?? '';
        } catch (\Exception) {
            $name = $convId = $botName = '';
        }

        $now = now();

        return array_merge([
            // Contact information
            'contact_name' => $name,
            'contact_phone' => $to,
            'contact_wa_id' => '',

            // Conversation information
            'conversation_id' => $convId,
            'bot_name' => $botName,

            // Date and time
            'current_date' => $now->format('F j, Y'),
            'current_time' => $now->format('g:i A'),
            'current_datetime' => $now->format('F j, Y g:i A'),
            'day_of_week' => $now->format('l'),
            'month' => $now->format('F'),
            'year' => $now->format('Y'),
        ], $variables);
    }

    /**
     * Resolve variables in a text string.
     * Automatically merges system variables if account and to are provided.
     */
    public function resolve(string $text, array $variables = []): string
    {
        // If we have a WhatsApp account and phone number in the variables,
        // we can automatically add system variables.
        $account = $variables['_account'] ?? null;
        $to = $variables['_to'] ?? null;

        if ($account instanceof WhatsappAccount && $to) {
            $variables = $this->systemVars($variables, $to, $account);
        }

        // Pass 1: scan for function calls, bracket-aware.
        $text = $this->resolveFunctionCalls($text, $variables);

        // Pass 2: f(x) shorthand
        $text = preg_replace_callback(
            '/f\(x\)\s+([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($m) {
                return $this->stringify($this->executeBuiltInFunction($m[1], []));
            },
            $text
        );

        // Pass 3: {{variable}}
        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            fn ($m) => $variables[$m[1]] ?? $m[0],
            $text
        );

        // Pass 4: ${variable}
        $text = preg_replace_callback(
            '/\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            fn ($m) => $variables[$m[1]] ?? $m[0],
            $text
        );

        // Pass 5: $variable (bare, not followed by an open paren)
        $text = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)(?!\s*\()/',
            fn ($m) => $variables[$m[1]] ?? $m[0],
            $text
        );

        return $text;
    }

    /**
     * Resolve text with context (account and phone number).
     * This automatically adds system variables.
     */
    public function resolveWithContext(
        string $text,
        WhatsappAccount $account,
        string $to,
        array $variables = []
    ): string {
        $variables['_account'] = $account;
        $variables['_to'] = $to;

        return $this->resolve($text, $variables);
    }

    /**
     * Resolve variables in an array with context.
     */
    public function resolveArrayWithContext(
        array $data,
        WhatsappAccount $account,
        string $to,
        array $variables = []
    ): array {
        $variables['_account'] = $account;
        $variables['_to'] = $to;

        return $this->resolveArray($data, $variables);
    }

    /**
     * Resolve variables in an array recursively.
     */
    public function resolveArray(array $data, array $variables = []): array
    {
        $resolved = [];
        foreach ($data as $key => $value) {
            $resolved[$key] = match (true) {
                is_array($value) => $this->resolveArray($value, $variables),
                is_string($value) => $this->resolve($value, $variables),
                default => $value,
            };
        }

        return $resolved;
    }

    /**
     * Resolve a single value.
     */
    public function resolveValue(mixed $value, array $variables = []): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // {{variable}}
        if (preg_match('/^\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}$/', $value, $m)) {
            return $variables[$m[1]] ?? null;
        }

        // f(x) name
        if (preg_match('/^f\(x\)\s+([a-zA-Z_][a-zA-Z0-9_]*)$/', $value, $m)) {
            return $this->executeBuiltInFunction($m[1], []);
        }

        // $funcName(args) — use the bracket-aware parser
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/s', $value, $m)) {
            $funcName = $m[1];
            $argsStr = $m[2];
            $params = $this->parseParameters($argsStr, $variables);

            return $this->executeBuiltInFunction($funcName, $params);
        }

        // $variable
        if (str_starts_with($value, '$')) {
            return $variables[substr($value, 1)] ?? null;
        }

        return $value;
    }

    // =========================================================================
    // PARSING
    // =========================================================================

    /**
     * Scans `$text` for `$funcName(...)` calls with balanced parentheses and
     * replaces each call with its evaluated result.
     *
     * This supports nesting: `$if($contains($name, "Dr"), "Doctor", "Mister")`
     * works because we find the matching close-paren by counting depth,
     * instead of relying on regex non-greedy matching.
     */
    private function resolveFunctionCalls(string $text, array $variables): string
    {
        $out = '';
        $i = 0;
        $len = strlen($text);

        while ($i < $len) {
            // Look for "$name("
            if ($text[$i] === '$' && $i + 1 < $len && $this->isIdentStart($text[$i + 1])) {
                // Try to match $name(
                $j = $i + 1;
                while ($j < $len && $this->isIdentChar($text[$j])) {
                    ++$j;
                }
                $funcName = substr($text, $i + 1, $j - $i - 1);

                if ($j < $len && $text[$j] === '(') {
                    // Find matching close-paren
                    $close = $this->findMatchingParen($text, $j);
                    if ($close !== -1) {
                        $argsStr = substr($text, $j + 1, $close - $j - 1);

                        // Recursively resolve any function calls inside args first
                        $argsStr = $this->resolveFunctionCalls($argsStr, $variables);

                        $params = $this->parseParameters($argsStr, $variables);
                        $result = $this->executeBuiltInFunction($funcName, $params);

                        $out .= $this->stringify($result);
                        $i = $close + 1;
                        continue;
                    }
                }
            }

            $out .= $text[$i];
            ++$i;
        }

        return $out;
    }

    /**
     * Given a string and the position of an open paren, returns the index of
     * the matching close paren (respecting quoted strings and escapes).
     * Returns -1 if no match found.
     */
    private function findMatchingParen(string $text, int $openPos): int
    {
        $depth = 0;
        $len = strlen($text);
        $inQuote = false;
        $quoteChar = null;

        for ($i = $openPos; $i < $len; ++$i) {
            $ch = $text[$i];

            // Skip escaped chars inside quotes
            if ($inQuote && $ch === '\\' && $i + 1 < $len) {
                ++$i;
                continue;
            }

            if ($inQuote) {
                if ($ch === $quoteChar) {
                    $inQuote = false;
                    $quoteChar = null;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuote = true;
                $quoteChar = $ch;
                continue;
            }

            if ($ch === '(') {
                ++$depth;
            } elseif ($ch === ')') {
                --$depth;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * Split a comma-separated argument string, respecting quotes and nested parens.
     */
    private function parseParameters(string $paramsString, array $variables): array
    {
        $paramsString = trim($paramsString);
        if ($paramsString === '') {
            return [];
        }

        $params = [];
        $current = '';
        $depth = 0;
        $inQuote = false;
        $quoteChar = null;
        $len = strlen($paramsString);

        for ($i = 0; $i < $len; ++$i) {
            $ch = $paramsString[$i];

            if ($inQuote) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $ch.$paramsString[$i + 1];
                    ++$i;
                    continue;
                }
                if ($ch === $quoteChar) {
                    $inQuote = false;
                    $quoteChar = null;
                    continue;   // strip outer quote
                }
                $current .= $ch;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuote = true;
                $quoteChar = $ch;
                continue;
            }

            if ($ch === '(') {
                ++$depth;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                --$depth;
                $current .= $ch;
                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $params[] = $this->resolveParam(trim($current), $variables);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '' || count($params) > 0) {
            $params[] = $this->resolveParam(trim($current), $variables);
        }

        return $params;
    }

    /**
     * Resolve a single param: variable ref, {{var}}, or literal.
     */
    private function resolveParam(string $param, array $variables): mixed
    {
        if ($param === '') {
            return '';
        }

        // $var (but not $func(...))
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)$/', $param, $m)) {
            return $variables[$m[1]] ?? $param;
        }

        // {{var}}
        if (preg_match('/^\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}$/', $param, $m)) {
            return $variables[$m[1]] ?? $param;
        }

        return $param;
    }

    private function isIdentStart(string $ch): bool
    {
        return ctype_alpha($ch) || $ch === '_';
    }

    private function isIdentChar(string $ch): bool
    {
        return ctype_alnum($ch) || $ch === '_';
    }

    private function stringify(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_null($v)) {
            return '';
        }
        if (is_array($v)) {
            return json_encode($v);
        }

        return (string) $v;
    }

    // =========================================================================
    // BUILT-IN FUNCTIONS
    // =========================================================================

    private function executeBuiltInFunction(string $name, array $params): mixed
    {
        return match ($name) {
            // Date / Time
            'now' => now()->toDateTimeString(),
            'today' => now()->toDateString(),
            'date' => isset($params[0]) ? \Carbon\Carbon::parse($params[0])->format($params[1] ?? 'Y-m-d') : '',
            'addDays' => isset($params[0], $params[1]) ? \Carbon\Carbon::parse($params[0])->addDays((int) $params[1])->toDateString() : '',
            'subtractDays' => isset($params[0], $params[1]) ? \Carbon\Carbon::parse($params[0])->subDays((int) $params[1])->toDateString() : '',
            'dayOfWeek' => isset($params[0]) ? \Carbon\Carbon::parse($params[0])->format('l') : '',
            'formatDate' => isset($params[0]) ? \Carbon\Carbon::parse($params[0])->format($params[1] ?? 'F j, Y') : '',

            // String
            'upper' => isset($params[0]) ? strtoupper($params[0]) : '',
            'lower' => isset($params[0]) ? strtolower($params[0]) : '',
            'capitalize' => isset($params[0]) ? ucfirst($params[0]) : '',
            'titleCase' => isset($params[0]) ? ucwords($params[0]) : '',
            'trim' => isset($params[0]) ? trim($params[0]) : '',
            'length' => isset($params[0]) ? strlen($params[0]) : 0,
            'substring' => isset($params[0], $params[1]) ? substr($params[0], (int) $params[1], isset($params[2]) ? (int) $params[2] : null) : '',
            'replace' => isset($params[0], $params[1], $params[2]) ? str_replace($params[1], $params[2], $params[0]) : '',
            'split' => isset($params[0], $params[1]) ? explode($params[1], $params[0]) : [],
            'join' => isset($params[0], $params[1]) && is_array($params[0]) ? implode($params[1], $params[0]) : '',
            'contains' => isset($params[0], $params[1]) ? (str_contains((string) $params[0], (string) $params[1]) ? 'true' : 'false') : 'false',
            'startsWith' => isset($params[0], $params[1]) ? (str_starts_with((string) $params[0], (string) $params[1]) ? 'true' : 'false') : 'false',
            'endsWith' => isset($params[0], $params[1]) ? (str_ends_with((string) $params[0], (string) $params[1]) ? 'true' : 'false') : 'false',

            // Formatting
            'formatNumber' => isset($params[0]) ? number_format((float) $params[0], (int) ($params[1] ?? 2)) : '',
            'formatCurrency' => isset($params[0]) ? '$'.number_format((float) $params[0], 2) : '',
            'formatPhone' => isset($params[0]) ? $this->formatPhone($params[0]) : '',
            'formatPercentage' => isset($params[0]) ? round((float) $params[0] * 100, 2).'%' : '',

            // Logical
            'if' => isset($params[0], $params[1], $params[2]) ? ($this->isTruthy($params[0]) ? $params[1] : $params[2]) : '',
            'isEmpty' => isset($params[0]) ? (empty($params[0]) ? 'true' : 'false') : 'true',
            'isNotEmpty' => isset($params[0]) ? (!empty($params[0]) ? 'true' : 'false') : 'false',
            'and' => $this->evaluateAnd($params),
            'or' => $this->evaluateOr($params),
            'not' => isset($params[0]) ? (!$this->isTruthy($params[0]) ? 'true' : 'false') : 'true',

            // Math
            'add' => isset($params[0], $params[1]) ? (float) $params[0] + (float) $params[1] : 0,
            'subtract' => isset($params[0], $params[1]) ? (float) $params[0] - (float) $params[1] : 0,
            'multiply' => isset($params[0], $params[1]) ? (float) $params[0] * (float) $params[1] : 0,
            'divide' => isset($params[0], $params[1]) && (float) $params[1] !== 0.0 ? (float) $params[0] / (float) $params[1] : 0,
            'round' => isset($params[0]) ? round((float) $params[0], (int) ($params[1] ?? 0)) : 0,
            'floor' => isset($params[0]) ? floor((float) $params[0]) : 0,
            'ceil' => isset($params[0]) ? ceil((float) $params[0]) : 0,
            'min' => !empty($params) ? min(array_map('floatval', $params)) : 0,
            'max' => !empty($params) ? max(array_map('floatval', $params)) : 0,
            'random' => isset($params[0], $params[1]) ? rand((int) $params[0], (int) $params[1]) : 0,

            // Array
            'arrayLength' => isset($params[0]) && is_array($params[0]) ? count($params[0]) : 0,
            'first' => isset($params[0]) && is_array($params[0]) && !empty($params[0]) ? reset($params[0]) : null,
            'last' => isset($params[0]) && is_array($params[0]) && !empty($params[0]) ? end($params[0]) : null,
            'indexOf' => isset($params[0], $params[1]) && is_array($params[0]) ? array_search($params[1], $params[0]) : -1,

            default => "UNKNOWN_FUNCTION({$name})",
        };
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10) {
            return sprintf('(%s) %s-%s', substr($phone, 0, 3), substr($phone, 3, 3), substr($phone, 6));
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
        foreach ($params as $p) {
            if (!$this->isTruthy($p)) {
                return 'false';
            }
        }

        return 'true';
    }

    private function evaluateOr(array $params): string
    {
        foreach ($params as $p) {
            if ($this->isTruthy($p)) {
                return 'true';
            }
        }

        return 'false';
    }
}
