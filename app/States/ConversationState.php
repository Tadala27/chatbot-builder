<?php

// =============================================================================
// CONVERSATION STATE MACHINE — spatie/laravel-model-states integration
// PRIORITY: 3 — Architectural
//
// WHAT: Replaces ad-hoc string status transitions with a proper state machine.
//
// WHY:
//   - Transitions are explicit and guarded (can't skip states)
//   - New states are a class, not a magic string
//   - Each state can carry behavior (e.g., what messages are allowed)
//   - Invalid transitions throw, surfacing bugs immediately
//
// INSTALL:
//   composer require spatie/laravel-model-states
// =============================================================================


// =============================================================================
// 1. BASE STATE — app/States/ConversationState.php
// =============================================================================

namespace App\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class ConversationState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Active::class)
            ->allowTransition(Active::class,    Completed::class)
            ->allowTransition(Active::class,    Abandoned::class)
            ->allowTransition(Active::class,    HandedOff::class)
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
}