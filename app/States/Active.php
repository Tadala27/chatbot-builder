<?php


namespace App\States;

class Active extends ConversationState
{
    public static string $name = 'active';
    public function acceptsMessages(): bool { return true; }
    public function label(): string { return 'Active'; }
}