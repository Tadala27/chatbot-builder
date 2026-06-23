<?php

namespace App\States;

class Abandoned extends ConversationState
{
    public static string $name = 'abandoned';
    public function acceptsMessages(): bool { return false; }
    public function label(): string { return 'Abandoned'; }
}