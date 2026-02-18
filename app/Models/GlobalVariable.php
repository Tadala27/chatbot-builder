<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'data_type',
        'is_encrypted',
        'description',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Accessors & Mutators
    public function getValueAttribute($value)
    {
        if ($this->is_encrypted && $value) {
            return decrypt($value);
        }
        return $value;
    }

    public function setValueAttribute($value)
    {
        if ($this->is_encrypted && $value) {
            $this->attributes['value'] = encrypt($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    // Helper methods
    public function getCastedValue()
    {
        return $this->castValue($this->value);
    }

    public function castValue($value)
    {
        if ($value === null) {
            return null;
        }

        return match ($this->data_type) {
            'string' => (string) $value,
            'number' => is_numeric($value) ? +$value : 0,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'date' => \Carbon\Carbon::parse($value),
            default => $value,
        };
    }
}