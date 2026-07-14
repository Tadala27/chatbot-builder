<?php

namespace App\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class ConversationState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Active::class)
            ->allowTransition(Active::class, Completed::class)
            ->allowTransition(Active::class, Abandoned::class)
            ->allowTransition(Active::class, HandedOff::class)
            ->allowTransition(Completed::class, Active::class)    // reopen
            ->allowTransition(Abandoned::class, Active::class)    // reopen
            ->allowTransition(HandedOff::class, Active::class)    // agent returns to bot
            ->allowTransition(HandedOff::class, Completed::class);
    }

    /**
     * Can the bot process new messages for a conversation in this state?
     */
    abstract public function acceptsMessages(): bool;

    /**
     * Human-readable label (for the inbox UI).
     */
    abstract public function label(): string;

    /**
     * Color for UI indicators
     */
    public function color(): string
    {
        return match($this::class) {
            Active::class => 'green',
            Completed::class => 'gray',
            Abandoned::class => 'red',
            HandedOff::class => 'blue',
            default => 'gray'
        };
    }
}