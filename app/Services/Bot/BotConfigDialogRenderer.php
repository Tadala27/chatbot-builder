<?php

namespace App\Services\Bot;

use App\Models\BotDialog;
use App\Models\Conversation;

class BotConfigDialogRenderer
{
    public function __construct(
        private WhatsAppMessageService $messageService,
    ) {
    }

    public function render(Conversation $conversation, BotDialog $dialog, array $variables = []): void
    {
        $account = $conversation->whatsappAccount;
        $to = $conversation->whatsapp_user_phone;
        $text = $dialog->config['text'] ?? '';

        match ($dialog->kind) {
            BotDialog::KIND_BUTTONS => $this->messageService->sendButtonMessage(
                $account,
                $to,
                $text,
                array_map(
                    fn (array $btn) => ['id' => $btn['id'], 'title' => $btn['label']],
                    $dialog->config['buttons'] ?? []
                ),
                variables: $variables,
            ),

            BotDialog::KIND_LIST => $this->messageService->sendListMessage(
                $account,
                $to,
                $text,
                'Choose an option',
                array_map(
                    fn (array $section) => [
                        'title' => $section['title'] ?? null,
                        'rows' => array_map(
                            fn (array $row) => ['id' => $row['id'], 'title' => $row['label']],
                            $section['rows'] ?? []
                        ),
                    ],
                    $dialog->config['sections'] ?? []
                ),
                variables: $variables,
            ),

            default => $this->sendPlainText($conversation, $text, $variables),
        };
    }

    public function sendPlainText(Conversation $conversation, ?string $text, array $variables = []): void
    {
        if (!$text || trim($text) === '') {
            return;
        }

        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $text,
            $variables,
        );
    }
}