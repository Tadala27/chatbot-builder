<?php

// =============================================================================
// FILE: app/Services/Flow/DialogRenderer.php  (NEW)
// PRIORITY: 3 — Architectural refactor
//
// WHAT: All the executeXxxDialog() methods extracted from ChatbotFlowExecutor.
// This class knows how to TAKE a dialog + resolved variables and SEND a
// WhatsApp message. It doesn't know about flow state, navigation, or context.
//
// BENEFIT: You can unit-test rendering without touching the DB or messaging.
// =============================================================================

namespace App\Services\Flow;

use App\Models\BotMediaFile;
use App\Models\Conversation;
use App\Models\Dialog;
use App\Services\Bot\VariableResolver;
use App\Services\Bot\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;

class DialogRenderer
{
    public function __construct(
        private WhatsAppMessageService $messageService,
        private VariableResolver       $variableResolver,
    ) {}

    /**
     * Render and send a dialog to the contact.
     *
     * @return array{success: bool, stop: bool, error?: string}
     *   success — did we successfully send the message?
     *   stop    — should the flow pause and wait for user input after this?
     *   error   — human-readable reason on failure
     */
    public function render(Dialog $dialog, Conversation $conversation, array $variables): array
    {
        return match ($dialog->kind) {
            'trigger'     => ['success' => true, 'stop' => false],
            'message'     => $this->renderMessage($dialog, $conversation, $variables),
            'buttons'     => $this->renderButtons($dialog, $conversation, $variables),
            'list'        => $this->renderList($dialog, $conversation, $variables),
            'media'       => $this->renderMedia($dialog, $conversation, $variables),
            'location'    => $this->renderLocation($dialog, $conversation, $variables),
            'contact'     => $this->renderContact($dialog, $conversation, $variables),
            'end'         => $this->renderEnd($dialog, $conversation, $variables),
            'nav_buttons' => $this->renderNavButtons($dialog, $conversation, $variables),
            default       => ['success' => false, 'stop' => false, 'error' => "Unknown dialog kind: {$dialog->kind}"],
        };
    }

    // =========================================================================

    private function renderMessage(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $text = $this->variableResolver->resolve($dialog->config['text'] ?? '', $vars);

        $this->messageService->sendTextMessage(
            $conv->whatsappAccount,
            $conv->whatsapp_user_phone,
            $text,
            $vars
        );

        // The caller decides whether to stop based on whether this dialog
        // has a variable action (text-input). We return stop=false here;
        // the executor will override when it sees a variable action.
        $stop = !empty($dialog->config['inputVariable']) || !empty($dialog->input_variable);

        return ['success' => true, 'stop' => $stop];
    }

    private function renderButtons(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $text   = $this->variableResolver->resolve($config['btnText'] ?? $config['text'] ?? '', $vars);

        $buttons = array_map(fn($b) => [
            'id'    => $b['id'],
            'title' => $this->variableResolver->resolve($b['label'] ?? $b['title'] ?? '', $vars),
        ], $config['buttons'] ?? []);

        $this->messageService->sendButtonMessage(
            $conv->whatsappAccount,
            $conv->whatsapp_user_phone,
            $text,
            $buttons,
            null,
            null,
            $vars
        );

        return ['success' => true, 'stop' => true];
    }

    private function renderList(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;

        $header     = $this->variableResolver->resolve($config['listHeader'] ?? '', $vars);
        $body       = $this->variableResolver->resolve($config['listBody'] ?? $config['text'] ?? '', $vars);
        $footer     = $this->variableResolver->resolve($config['listFooter'] ?? '', $vars);
        $buttonText = $this->variableResolver->resolve(
            $config['action']['button'] ?? $config['buttonText'] ?? 'View Options',
            $vars
        );

        $sections = [];
        foreach ($config['action']['sections'] ?? $config['sections'] ?? [] as $section) {
            $rows = array_map(fn($row) => [
                'id'          => $row['id'],
                'title'       => $this->variableResolver->resolve($row['title']       ?? '', $vars),
                'description' => $this->variableResolver->resolve($row['description'] ?? '', $vars),
            ], $section['rows'] ?? []);

            $sections[] = [
                'title' => $this->variableResolver->resolve($section['title'] ?? '', $vars),
                'rows'  => $rows,
            ];
        }

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

        return ['success' => true, 'stop' => true];
    }

    private function renderNavButtons(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config  = $dialog->config;
        $buttons = [];

        $spec = [
            'includeStartFlow'   => ['prefix' => 'sys_start_flow',  'label' => 'labelStartFlow',   'default' => 'Start Flow',   'action' => 'start_flow'],
            'includeGoHome'      => ['prefix' => 'sys_go_home',     'label' => 'labelGoHome',      'default' => 'Main Menu',    'action' => 'go_home'],
            'includeGoBack'      => ['prefix' => 'sys_go_back',     'label' => 'labelGoBack',      'default' => 'Go Back',      'action' => 'go_back'],
            'includeTalkToAgent' => ['prefix' => 'sys_talk_agent',  'label' => 'labelTalkToAgent', 'default' => 'Talk to Agent','action' => 'talk_to_agent'],
        ];

        foreach ($spec as $configKey => $meta) {
            if (!empty($config[$configKey])) {
                $buttons[] = [
                    'id'      => "{$meta['prefix']}_{$dialog->id}",
                    'title'   => $config[$meta['label']] ?? $meta['default'],
                    'actions' => [['kind' => $meta['action']]],
                ];
            }
        }

        if (empty($buttons)) return ['success' => true, 'stop' => false];

        $buttons = array_slice($buttons, 0, 3);
        $text    = $this->variableResolver->resolve(
            $config['text'] ?? 'What would you like to do?',
            $vars
        );

        $this->messageService->sendButtonMessage(
            $conv->whatsappAccount,
            $conv->whatsapp_user_phone,
            $text,
            array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title']], $buttons),
            null,
            null,
            $vars
        );

        // Store materialized buttons on the dialog so system-action detection
        // can find them. The executor will handle persisting this.
        $dialog->config = array_merge($config, ['buttons' => $buttons]);

        return ['success' => true, 'stop' => true];
    }

    private function renderMedia(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config    = $dialog->config;
        $mediaFile = !empty($config['mediaFileId']) ? BotMediaFile::find($config['mediaFileId']) : null;

        $url      = $mediaFile?->url ?? $this->variableResolver->resolve($config['mediaUrl'] ?? '', $vars);
        $mimeType = $mediaFile?->mime_type;

        if (empty($url)) {
            return ['success' => false, 'stop' => false, 'error' => 'Media URL is required'];
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'stop' => false, 'error' => 'Invalid media URL'];
        }

        $mediaType      = $config['mediaType'] ?? 'image';
        $caption        = $this->variableResolver->resolve($config['mediaCaption']  ?? '', $vars);
        $filename       = $this->variableResolver->resolve($config['mediaFilename'] ?? '', $vars);
        $stop           = !empty($config['waitForReply']);
        $isUploadedFile = $mediaFile !== null;

        // If the URL isn't a media file (e.g. a YouTube link), send as text with preview
        if (!$isUploadedFile && !$this->urlLooksLikeMediaFile($url)) {
            $body = trim(($caption ? $caption . "\n" : '') . $url);
            $this->messageService->sendTextMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $body,
                $vars
            );
            return ['success' => true, 'stop' => $stop];
        }

        if (!$isUploadedFile && $mediaType === 'document') {
            $mimeType = $this->inferMimeType($url, $mediaType);
        }

        try {
            $this->messageService->sendMediaMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $mediaType,
                $url,
                $caption,
                $filename,
                $vars,
                $mimeType
            );
            return ['success' => true, 'stop' => $stop];
        } catch (\Exception $e) {
            Log::error('Failed to send media dialog', ['dialog_id' => $dialog->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
        }
    }

    private function renderLocation(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $config = $dialog->config;
        $lat    = (float) ($config['locationLatitude']  ?? 0);
        $lng    = (float) ($config['locationLongitude'] ?? 0);

        if ($lat == 0.0 || $lng == 0.0) {
            return ['success' => false, 'stop' => false, 'error' => 'Valid coordinates are required'];
        }

        try {
            $this->messageService->sendLocationMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $lat,
                $lng,
                $this->variableResolver->resolve($config['locationName']    ?? '', $vars),
                $this->variableResolver->resolve($config['locationAddress'] ?? '', $vars)
            );
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
        }
    }

    private function renderContact(Dialog $dialog, Conversation $conv, array $vars): array
    {
        $contactData = $dialog->config['contactData'] ?? [];

        if (empty($contactData['name']['formatted_name'])) {
            return ['success' => false, 'stop' => false, 'error' => 'Contact name is required'];
        }

        try {
            $this->messageService->sendContactMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $this->resolveContactVariables($contactData, $vars)
            );
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            return ['success' => false, 'stop' => false, 'error' => $e->getMessage()];
        }
    }

    private function renderEnd(Dialog $dialog, Conversation $conv, array $vars): array
    {
        if (!empty($dialog->config['text'])) {
            $this->messageService->sendTextMessage(
                $conv->whatsappAccount,
                $conv->whatsapp_user_phone,
                $this->variableResolver->resolve($dialog->config['text'], $vars),
                $vars
            );
        }
        return ['success' => true, 'stop' => true];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function resolveContactVariables(array $contactData, array $vars): array
    {
        $resolve = fn($v) => is_string($v) ? $this->variableResolver->resolve($v, $vars) : $v;

        if (isset($contactData['name'])) {
            $contactData['name'] = array_map($resolve, $contactData['name']);
        }

        foreach (['phones', 'emails', 'addresses', 'urls'] as $field) {
            if (!isset($contactData[$field])) continue;
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
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') return false;

        return in_array($ext, [
            'jpg', 'jpeg', 'png', 'webp', 'gif',
            'mp4', '3gp', '3gpp', 'mov', 'avi', 'mkv', 'webm',
            'mp3', 'aac', 'amr', 'ogg', 'oga', 'opus', 'm4a', 'wav', 'flac',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'csv',
        ], true);
    }

    private function inferMimeType(string $url, string $mediaType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'gif' => 'image/gif',
            'mp4' => 'video/mp4', '3gp' => 'video/3gpp', '3gpp' => 'video/3gpp',
            'mov' => 'video/quicktime',
            'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg; codecs=opus',
            'mp3' => 'audio/mpeg', 'aac' => 'audio/aac', 'amr' => 'audio/amr', 'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
        ];

        if (isset($map[$ext])) return $map[$ext];

        return match ($mediaType) {
            'video'    => 'video/mp4',
            'audio'    => 'audio/mpeg',
            'image'    => 'image/jpeg',
            'document' => 'application/pdf',
            'sticker'  => 'image/webp',
            default    => 'application/octet-stream',
        };
    }
}