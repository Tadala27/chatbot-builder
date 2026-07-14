<?php

namespace App\Services\Bot;

use App\Models\BotMediaFile;
use App\Models\Conversation;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

class DialogRenderer
{
    public function __construct(
        private WhatsAppMessageService $messageService,
        private VariableResolver $variableResolver,
    ) {
    }

    public function render(Dialog $dialog, Conversation $conversation, array $variables): array
    {
        return match ($dialog->kind) {
            'trigger' => ['success' => true, 'stop' => false],
            'message' => $this->renderMessage($dialog, $conversation, $variables),
            'buttons' => $this->renderButtons($dialog, $conversation, $variables),
            'list' => $this->renderList($dialog, $conversation, $variables),
            'media' => $this->renderMedia($dialog, $conversation, $variables),
            'location' => $this->renderLocation($dialog, $conversation, $variables),
            'contact' => $this->renderContact($dialog, $conversation, $variables),
            'end' => $this->renderEnd($dialog, $conversation, $variables),
            'nav_buttons' => $this->renderNavButtons($dialog, $conversation, $variables),
            default => ['success' => false, 'stop' => false, 'error' => "Unknown dialog kind: {$dialog->kind}"],
        };
    }

    public function handleUnsupportedMessage(
        Conversation $conversation,
        string $messageType,
        array $variables
    ): ?array {
        if ($conversation->status === 'handed_off') {
            return null;
        }

        $account = $conversation->whatsappAccount;
        $to = $conversation->whatsapp_user_phone;

        $label = match ($messageType) {
            'sticker' => 'sticker',
            'reaction' => 'emoji reaction',
            default => 'media type',
        };

        $this->typing($conversation, true);

        try {
            $this->messageService->sendButtonMessage(
                $account,
                $to,
                "We received your {$label}, but the bot can't process it here. Please continue by tapping Go Back.",
                [
                    [
                        'id' => "sys_go_back_{$conversation->id}",
                        'title' => 'Go Back',
                        'actions' => [['kind' => 'go_back']],
                    ],
                ],
                null,
                null,
                $variables
            );
        } finally {
            $this->typing($conversation, false);
        }

        return ['success' => true, 'stop' => true];
    }

    // =========================================================================
    // RENDER METHODS
    // =========================================================================

    /**
     * Resolve text with context (account and phone number) for system variables.
     */
    private function resolveWithContext(string $text, Conversation $conv, array $vars): string
    {
        return $this->variableResolver->resolveWithContext(
            $text,
            $conv->whatsappAccount,
            $conv->whatsapp_user_phone,
            $vars
        );
    }

    private function renderMessage(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $text = $this->resolveWithContext($dialog->config['text'] ?? '', $conv, $vars);
        $stop = !empty($dialog->config['inputVariable']) || !empty($dialog->input_variable);

        $this->typing($conv, true);

        try {
            $this->messageService->sendTextMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $text,
                $vars
            );
        } finally {
            $this->typing($conv, false);
        }

        return ['success' => true, 'stop' => $stop];
    }

    private function renderButtons(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $text = $this->resolveWithContext($config['btnText'] ?? $config['text'] ?? '', $conv, $vars);
        $buttons = array_map(function ($b) use ($conv, $vars) {
            return [
                'id' => $b['id'],
                'title' => $this->resolveWithContext($b['label'] ?? $b['title'] ?? '', $conv, $vars),
                'type' => $b['type'] ?? 'reply',
                'url' => isset($b['url']) ? $this->resolveWithContext($b['url'], $conv, $vars) : null,
            ];
        }, $config['buttons'] ?? []);

        $this->typing($conv, true);

        try {
            $this->messageService->sendButtonMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $text,
                $buttons,
                null,
                null,
                $vars
            );
        } finally {
            $this->typing($conv, false);
        }

        return ['success' => true, 'stop' => true];
    }

    private function renderList(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $header = $this->resolveWithContext($config['listHeader'] ?? '', $conv, $vars);
        $body = $this->resolveWithContext($config['listBody'] ?? $config['text'] ?? '', $conv, $vars);
        $footer = $this->resolveWithContext($config['listFooter'] ?? '', $conv, $vars);
        $buttonText = $this->resolveWithContext(
            $config['action']['button'] ?? $config['buttonText'] ?? 'View Options',
            $conv,
            $vars
        );

        $sections = [];
        foreach ($config['action']['sections'] ?? $config['sections'] ?? [] as $section) {
            $rows = array_map(function ($row) use ($conv, $vars) {
                return [
                    'id' => $row['id'],
                    'title' => $this->resolveWithContext($row['title'] ?? '', $conv, $vars),
                    'description' => $this->resolveWithContext($row['description'] ?? '', $conv, $vars),
                ];
            }, $section['rows'] ?? []);

            $sections[] = [
                'title' => $this->resolveWithContext($section['title'] ?? '', $conv, $vars),
                'rows' => $rows,
            ];
        }

        $this->typing($conv, true);

        try {
            $this->messageService->sendListMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $body,
                $buttonText,
                $sections,
                $header,
                $footer,
                $vars
            );
        } finally {
            $this->typing($conv, false);
        }

        return ['success' => true, 'stop' => true];
    }

    private function renderMedia(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $mediaType = $config['mediaType'] ?? 'image';

        // Resolve caption through VariableResolver so {{variables}} and $contact_name work
        $caption = !empty($config['mediaCaption'])
            ? $this->resolveWithContext($config['mediaCaption'], $conv, $vars)
            : null;

        $filename = !empty($config['mediaFilename'])
            ? $this->resolveWithContext($config['mediaFilename'], $conv, $vars)
            : null;

        $stop = !empty($config['waitForReply']);

        // ── PATH 1: uploaded BotMediaFile ────────────────────────────────────
        if (!empty($config['mediaFileId'])) {
            $mediaFile = BotMediaFile::find($config['mediaFileId']);

            if (!$mediaFile) {
                return ['success' => false, 'stop' => false,
                    'error' => "BotMediaFile [{$config['mediaFileId']}] not found."];
            }

            $this->typing($conv, true);

            try {
                $this->messageService->sendStoredMediaFile(
                    $conv->whatsappAccount,
                    $conv->whatsapp_user_phone,
                    $mediaFile,
                    $caption,
                );
            } catch (\Exception $e) {
                Log::error('Failed to send stored media', [
                    'dialog_id' => $dialog->id,
                    'media_file_id' => $config['mediaFileId'],
                    'error' => $e->getMessage(),
                ]);

                return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
            } finally {
                $this->typing($conv, false);
            }

            return ['success' => true, 'stop' => $stop];
        }

        // ── PATH 2: external / public URL ────────────────────────────────────
        $url = $this->resolveWithContext($config['mediaUrl'] ?? '', $conv, $vars);

        if (empty($url)) {
            return ['success' => false, 'stop' => false, 'error' => 'No media source set on this node.'];
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'stop' => false, 'error' => 'Invalid media URL.'];
        }

        if (!$this->urlLooksLikeMediaFile($url)) {
            // Looks like a link (YouTube etc.) — send as text with preview
            $body = trim(($caption ? $caption . "\n" : '') . $url);
            $this->typing($conv, true);

            try {
                $this->messageService->sendTextMessage(
                    $conv->whatsappAccount,
                    $conv->whatsapp_user_phone,
                    $body,
                    $vars
                );
            } finally {
                $this->typing($conv, false);
            }

            return ['success' => true, 'stop' => $stop];
        }

        $this->typing($conv, true);

        try {
            $this->messageService->sendMediaMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $mediaType,
                $url,
                $caption,
                $mediaType === 'document' ? $filename : null,
            );
        } catch (\Exception $e) {
            Log::error('Failed to send media dialog', [
                'dialog_id' => $dialog->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
        } finally {
            $this->typing($conv, false);
        }

        return ['success' => true, 'stop' => $stop];
    }

    private function renderLocation(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $lat = (float) ($config['locationLatitude'] ?? 0);
        $lng = (float) ($config['locationLongitude'] ?? 0);

        if ($lat == 0.0 || $lng == 0.0) {
            return ['success' => false, 'stop' => false, 'error' => 'Valid coordinates are required'];
        }

        $stop = !empty($config['waitForReply']);

        $this->typing($conv, true);

        try {
            $this->messageService->sendLocationMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $lat,
                $lng,
                $this->resolveWithContext($config['locationName'] ?? '', $conv, $vars) ?: null,
                $this->resolveWithContext($config['locationAddress'] ?? '', $conv, $vars) ?: null,
            );
        } catch (\Exception $e) {
            return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
        } finally {
            $this->typing($conv, false);
        }

        return ['success' => true, 'stop' => $stop];
    }

    private function renderContact(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $contactData = $dialog->config['contactData'] ?? [];

        if (empty($contactData['name']['formatted_name'])) {
            return ['success' => false, 'stop' => false, 'error' => 'Contact name is required'];
        }

        $stop = !empty($dialog->config['waitForReply']);

        $this->typing($conv, true);

        try {
            $this->messageService->sendContactMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $this->resolveContactVariables($contactData, $conv, $vars)
            );
        } catch (\Exception $e) {
            return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
        } finally {
            $this->typing($conv, false);
        }

        return ['success' => true, 'stop' => $stop];
    }

    private function renderEnd(Dialog $dialog, Conversation $conv, array $vars): array
    {
        if (!empty($dialog->config['text'])) {
            $this->typing($conv, true);

            try {
                $this->messageService->sendTextMessage(
                    $conv->whatsappAccount,
                    $conv->whatsapp_user_phone,
                    $this->resolveWithContext($dialog->config['text'], $conv, $vars),
                    $vars
                );
            } finally {
                $this->typing($conv, false);
            }
        }

        return ['success' => true, 'stop' => true];
    }

    private function renderNavButtons(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $buttons = [];

        $spec = [
            'includeStartFlow' => ['prefix' => 'sys_start_flow', 'label' => 'labelStartFlow', 'default' => 'Start Flow', 'action' => 'start_flow'],
            'includeGoHome' => ['prefix' => 'sys_go_home', 'label' => 'labelGoHome', 'default' => 'Main Menu', 'action' => 'go_home'],
            'includeGoBack' => ['prefix' => 'sys_go_back', 'label' => 'labelGoBack', 'default' => 'Go Back', 'action' => 'go_back'],
            'includeTalkToAgent' => ['prefix' => 'sys_talk_agent', 'label' => 'labelTalkToAgent', 'default' => 'Talk to Agent', 'action' => 'talk_to_agent'],
        ];

        foreach ($spec as $configKey => $meta) {
            if (!empty($config[$configKey])) {
                $buttons[] = [
                    'id' => "{$meta['prefix']}_{$dialog->id}",
                    'title' => $config[$meta['label']] ?? $meta['default'],
                    'actions' => [['kind' => $meta['action']]],
                ];
            }
        }

        if (empty($buttons)) {
            return ['success' => true, 'stop' => false];
        }

        $buttons = array_slice($buttons, 0, 3);
        $text = $this->resolveWithContext(
            $config['text'] ?? 'What would you like to do?',
            $conv,
            $vars
        );

        $this->typing($conv, true);

        try {
            $this->messageService->sendButtonMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $text,
                array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title']], $buttons),
                null,
                null,
                $vars
            );
        } finally {
            $this->typing($conv, false);
        }

        $dialog->config = array_merge($config, ['buttons' => $buttons]);

        return ['success' => true, 'stop' => true];
    }

    // =========================================================================
    // TYPING / READ INDICATORS
    // =========================================================================

    /**
     * Mark the last inbound message as read (shows blue ticks on the user's phone)
     * and show/hide the typing indicator in the same call.
     */
    private function typing(Conversation $conversation, bool $isTyping): void
    {
        try {
            if ($isTyping) {
                $lastInbound = $conversation->messages()
                    ->where('direction', 'inbound')
                    ->whereNotNull('whatsapp_message_id')
                    ->latest('sent_at')
                    ->value('whatsapp_message_id');

                if ($lastInbound) {
                    $this->messageService->markAsRead(
                        $conversation->whatsappAccount,
                        $lastInbound
                    );
                }
            }

            $this->messageService->sendTypingIndicator(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $isTyping,
                $conversation
            );
        } catch (\Exception $e) {
            Log::debug('[DialogRenderer] Typing indicator failed (non-fatal)', [
                'conversation_id' => $conversation->id,
                'is_typing' => $isTyping,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function resolveContactVariables(array $contactData, Conversation $conv, array $vars): array
    {
        $resolve = function ($v) use ($conv, $vars) {
            return is_string($v) ? $this->resolveWithContext($v, $conv, $vars) : $v;
        };

        if (isset($contactData['name'])) {
            $contactData['name'] = array_map($resolve, $contactData['name']);
        }

        foreach (['phones', 'emails', 'addresses', 'urls'] as $field) {
            if (!isset($contactData[$field])) {
                continue;
            }
            foreach ($contactData[$field] as $i => $item) {
                $contactData[$field][$i] = array_map($resolve, $item);
            }
        }

        if (isset($contactData['org'])) {
            $contactData['org'] = array_map($resolve, $contactData['org']);
        }

        return $contactData;
    }

    private function urlLooksLikeMediaFile(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }

        return in_array($ext, [
            'jpg', 'jpeg', 'png', 'webp', 'gif',
            'mp4', '3gp', '3gpp', 'mov', 'avi', 'mkv', 'webm',
            'mp3', 'aac', 'amr', 'ogg', 'oga', 'opus', 'm4a', 'wav', 'flac',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'csv',
        ], true);
    }
}