<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotDialog extends Model
{
    public const KIND_MESSAGE = 'message';
    public const KIND_BUTTONS = 'buttons';
    public const KIND_LIST = 'list';

    public const KINDS = [self::KIND_MESSAGE, self::KIND_BUTTONS, self::KIND_LIST];

    /**
     * Button/row "kind" values a config-level dialog's buttons can trigger.
     * These map 1:1 onto SystemActionHandler::execute()'s $kind switch, so a
     * button here and a button on a flow dialog are executed by the exact
     * same code — no separate "config-level" execution path needed.
     *
     * @see \App\Services\Bot\SystemActionHandler::execute()
     */
    public const SYSTEM_ACTIONS = ['start_flow', 'go_home', 'go_back', 'talk_to_agent'];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'bot_id',
        'purpose',
        'name',
        'description',
        'kind',
        'config',
        'is_entry_point',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_entry_point' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }
}
