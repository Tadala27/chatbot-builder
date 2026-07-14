<?php

namespace App\States;

class Completed extends ConversationState
{
    public static string $name = 'completed';

    public function acceptsMessages(): bool
    {
        return false;
    }  // needs explicit reopen

    public function label(): string
    {
        return 'Completed';
    }
}