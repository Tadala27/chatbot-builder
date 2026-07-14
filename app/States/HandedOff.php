<?php

namespace App\States;

class HandedOff extends ConversationState
{
    public static string $name = 'handed_off';

    public function acceptsMessages(): bool
    {
        return false;
    }  // agent handles it

    public function label(): string
    {
        return 'Handed off to agent';
    }
}