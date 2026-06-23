<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 
        'custom_variable_id', 
        'key', 
        'value',
        'value_type',
    ];

    protected $casts = [
        'conversation_id'    => 'integer',
        'custom_variable_id' => 'integer',
    ];
    
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** The bot-level variable schema this value belongs to (nullable) */
    public function customVariable(): BelongsTo
    {
        return $this->belongsTo(CustomVariable::class);
    }

        public function getTypedValue(): mixed
    {
        return self::coerceFromString($this->value, $this->value_type ?? 'string');
    }
 
    public static function coerceFromString(?string $raw, string $type): mixed
    {
        if ($raw === null) return null;
 
        return match ($type) {
            'number' => is_numeric($raw)
                ? (str_contains($raw, '.') ? (float) $raw : (int) $raw)
                : null,
            'boolean'  => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'json'     => json_decode($raw, true),
            'datetime' => \Carbon\Carbon::parse($raw),
            'null'     => null,
            default    => $raw,
        };
    }
        public function setTypedValue(mixed $value): self
    {
        [$stringValue, $type] = self::detectTypeAndEncode($value);
        $this->value      = $stringValue;
        $this->value_type = $type;
        return $this;
    }
 
    /**
     * @return array{0: string|null, 1: string}  [stringified value, type tag]
     */
    public static function detectTypeAndEncode(mixed $value): array
    {
        if ($value === null)      return [null, 'null'];
        if (is_bool($value))      return [$value ? '1' : '0', 'boolean'];
        if (is_int($value))       return [(string) $value, 'number'];
        if (is_float($value))     return [(string) $value, 'number'];
        if ($value instanceof \DateTimeInterface) {
            return [$value->format('Y-m-d H:i:s'), 'datetime'];
        }
        if (is_array($value) || is_object($value)) {
            return [json_encode($value), 'json'];
        }
 
        // String — but try to sniff if it looks like a typed literal
        $str = (string) $value;
        if (in_array(strtolower(trim($str)), ['true', 'false'], true)) {
            return [strtolower(trim($str)) === 'true' ? '1' : '0', 'boolean'];
        }
        if (is_numeric($str)) {
            return [$str, 'number'];
        }
        return [$str, 'string'];
    }
 
    public static function setForConversation(
        int    $conversationId,
        string $key,
        mixed  $value,
        ?int   $customVariableId = null
    ): self {
        [$stringValue, $type] = self::detectTypeAndEncode($value);
 
        return self::updateOrCreate(
            ['conversation_id' => $conversationId, 'key' => $key],
            [
                'value'              => $stringValue,
                'value_type'         => $type,
                'custom_variable_id' => $customVariableId,
            ]
        );
    }

    public static function getTypedForConversation(int $conversationId): array
    {
        $vars = self::where('conversation_id', $conversationId)
            ->get(['key', 'value', 'value_type']);
 
        $result = [];
        foreach ($vars as $var) {
            $result[$var->key] = $var->getTypedValue();
        }
        return $result;
    }
}