<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationVariable;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Executes the chatbot flow for a conversation.
 *
 * Schema changes from old version:
 *  - FlowNode  → Dialog  (table: dialogs, model: Dialog)
 *  - $version->nodes() → $version->dialogs()
 *  - Dialog has .kind (not .type); config['kind'] is still respected for legacy data
 *  - $conversation->setVariable() does NOT exist → use setVariable() helper here
 *  - Message has NO flow_node_id column → position tracked via ConversationContext.last_dialog_id
 *  - AnalyticsEvent has NO node_id column → dialog reference stored in metadata JSON
 *  - DB Actions: Action model has action_type + config; no trigger_event / execution_order columns.
 *    Actions are always post-send side-effects configured in config['actions'] on the Dialog config.
 */
class ChatbotFlowExecutor
{
    private Conversation $conversation;

    public function __construct(
        private WhatsAppMessageService $messageService,
        private VariableResolver       $variableResolver,
        private ActionExecutorService  $actionExecutor,
    ) {}

    // =========================================================================
    // MAIN ENTRY POINTS
    // =========================================================================

    /**
     * Handle an inbound WhatsApp message.
     * Called by ProcessChatbotMessage job.
     */
    public function processMessage(Conversation $conversation, Message $message): void
    {
        $this->conversation = $conversation;

        try {
            $flow    = $conversation->flow;
            $version = $conversation->flowVersion;

            if (!$flow || $flow->status !== 'published' || !$version) {
                Log::warning('Flow not available for conversation', [
                    'conversation_id' => $conversation->id,
                    'flow_id'         => $flow?->id,
                    'flow_status'     => $flow?->status,
                ]);
                return;
            }

            // ── Late-selection intercept ──────────────────────────────────────
            // WhatsApp users can scroll back up and tap a button or list item
            // from an earlier point in the conversation. When this happens the
            // inbound message is an interactive reply whose button/row ID does
            // NOT belong to the dialog the conversation is currently waiting on.
            //
            // Strategy: if the message is interactive, search ALL dialogs in the
            // flow for one that owns that selection ID. If found, treat it as a
            // fresh selection from that dialog — abandon the current position,
            // save the variable (if configured), and execute the target dialog.
            if ($message->message_type === 'interactive') {
                $selectionId = $message->content['response']['id'] ?? null;

                if ($selectionId) {
                    $ownerDialog = $this->findDialogOwningSelection($version, $selectionId);
                    $currentDialog = $this->getCurrentDialog($version, $conversation);

                    // Only intercept if the selection belongs to a DIFFERENT dialog
                    // than the one we're currently waiting on.
                    if ($ownerDialog && (!$currentDialog || $ownerDialog->id !== $currentDialog->id)) {
                        Log::info('Late-selection intercept: user tapped a button/row from a previous dialog', [
                            'conversation_id'  => $conversation->id,
                            'current_dialog_id' => $currentDialog?->id,
                            'owner_dialog_id'  => $ownerDialog->id,
                            'owner_dialog_kind' => $ownerDialog->kind,
                            'selection_id'     => $selectionId,
                        ]);

                        // Persist any saveVariable configured on this button/row
                        $this->processUserInput($ownerDialog, $message);

                        $variables  = $this->getVariables();
                        $nextDialog = $this->resolveNextDialogFromMessage($version, $ownerDialog, $message, $variables);

                        if ($nextDialog) {
                            $this->executeDialogFlow($nextDialog);
                        } else {
                            Log::warning('Late-selection: no target dialog found for selection', [
                                'conversation_id' => $conversation->id,
                                'owner_dialog_id' => $ownerDialog->id,
                                'selection_id'    => $selectionId,
                            ]);
                        }
                        return;
                    }
                }
            }

            // ── Normal flow: process against current dialog position ──────────
            $currentDialog = $this->getCurrentDialog($version, $conversation);

            if ($currentDialog) {
                Log::info('Current dialog found — processing user input', [
                    'conversation_id'   => $conversation->id,
                    'current_dialog_id' => $currentDialog->id,
                    'dialog_kind'       => $currentDialog->kind,
                ]);

                $this->processUserInput($currentDialog, $message);

                $variables  = $this->getVariables();
                $nextDialog = $this->resolveNextDialogFromMessage($version, $currentDialog, $message, $variables);

                if ($nextDialog) {
                    $this->executeDialogFlow($nextDialog);
                } else {
                    Log::warning('No next dialog found after user input', [
                        'conversation_id'   => $conversation->id,
                        'current_dialog_id' => $currentDialog->id,
                    ]);
                }
            } else {
                Log::info('No current dialog — starting flow from entry point', [
                    'conversation_id' => $conversation->id,
                ]);
                $this->startFlow($conversation);
            }
        } catch (\Exception $e) {
            Log::error('Error processing message', [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Search all dialogs in the flow version for the one that contains a button
     * or list row with the given ID. Used for late-selection intercept.
     *
     * Returns the Dialog that owns the selection, or null if not found.
     */
    private function findDialogOwningSelection($version, string $selectionId): ?Dialog
    {
        $dialogs = $version->dialogs()->whereIn('kind', ['buttons', 'list'])->get();

        foreach ($dialogs as $dialog) {
            if ($dialog->kind === 'buttons') {
                foreach ($dialog->config['buttons'] ?? [] as $btn) {
                    if (($btn['id'] ?? '') === $selectionId) {
                        return $dialog;
                    }
                }
            }

            if ($dialog->kind === 'list') {
                $sections = $dialog->config['action']['sections']
                    ?? $dialog->config['sections']
                    ?? [];
                foreach ($sections as $section) {
                    foreach ($section['rows'] ?? [] as $row) {
                        if (($row['id'] ?? '') === $selectionId) {
                            return $dialog;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Execute a dialog and keep the flow moving.
     * Called by ContinueChatbotFlow job (delayed resumption) and internally.
     *
     * Public so the job can call it directly.
     */
    public function executeDialogFlow(Dialog $dialog, ?Conversation $conversation = null): void
    {
        if ($conversation) {
            $this->conversation = $conversation;
        }

        try {
            $version = $this->conversation->flowVersion;
            if (!$version) return;

            Log::info('Executing dialog flow', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $dialog->id,
                'dialog_kind'     => $dialog->kind,
            ]);

            // Send message / perform the dialog's primary action
            $result = $this->executeDialog($dialog);

            Log::info('Dialog execution result', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $dialog->id,
                'result'          => $result,
            ]);

            if ($result['stop'] ?? false) {
                // Interactive dialog (buttons/list) — wait for user reply
                Log::info('Dialog waiting for user input', [
                    'conversation_id' => $this->conversation->id,
                    'dialog_id'       => $dialog->id,
                ]);
                return;
            }

            if ($result['success'] ?? false) {
                $variables = $this->getVariables();

                // Run any inline config actions (navigation, variable-set, etc.)
                $nextDialogId = $this->runConfigActions($dialog, $dialog->config['actions'] ?? [], $variables);

                // Fall back to direct config goTo
                if (!$nextDialogId && !empty($dialog->config['goTo'])) {
                    $nextDialogId = $dialog->config['goTo'];
                }

                if ($nextDialogId) {
                    $nextDialog = $version->dialogs()
                        ->where('config->id', $nextDialogId)
                        ->first();

                    if ($nextDialog) {
                        Log::info('Navigating to next dialog', [
                            'conversation_id' => $this->conversation->id,
                            'from_dialog_id'  => $dialog->id,
                            'to_dialog_id'    => $nextDialog->id,
                            'to_dialog_kind'  => $nextDialog->kind,
                        ]);
                        $this->executeDialogFlow($nextDialog);
                    } else {
                        Log::warning('Next dialog config.id not found', [
                            'conversation_id' => $this->conversation->id,
                            'dialog_id'       => $dialog->id,
                            'next_dialog_id'  => $nextDialogId,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error executing dialog flow', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $dialog->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    // =========================================================================
    // FLOW NAVIGATION
    // =========================================================================

    private function startFlow(Conversation $conversation): void
    {
        $this->conversation = $conversation;

        $startDialog = $conversation->flowVersion
            ?->dialogs()
            ->where('is_entry_point', true)
            ->first();

        if (!$startDialog) {
            Log::warning('No entry point dialog found', ['conversation_id' => $conversation->id]);
            return;
        }

        Log::info('Starting flow from entry point', [
            'conversation_id'   => $conversation->id,
            'start_dialog_id'   => $startDialog->id,
            'start_dialog_kind' => $startDialog->kind,
        ]);

        $this->executeDialogFlow($startDialog);
    }

    private function resolveNextDialogFromMessage(
        $version,
        Dialog  $currentDialog,
        Message $message,
        array   $variables
    ): ?Dialog {
        $kind = $currentDialog->kind;

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id'] ?? null,
            'text'        => $message->content['text']           ?? null,
            default       => null,
        };

        // ── Buttons ───────────────────────────────────────────────────────────
        if ($kind === 'buttons' && $selectionId) {
            // Look for a button-level goTo in config
            foreach ($currentDialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['goTo'])) {
                    $dialog = $version->dialogs()->where('config->id', $btn['goTo'])->first();
                    if ($dialog) return $dialog;
                }
            }

            // Fall through to config-level actions
            $targetId = $this->runConfigActions(
                $currentDialog,
                $this->getActionsForSelection($currentDialog, $selectionId),
                $variables
            );
            if ($targetId) {
                return $version->dialogs()->where('config->id', $targetId)->first();
            }
        }

        // ── List ──────────────────────────────────────────────────────────────
        if ($kind === 'list' && $selectionId) {
            $sections = $currentDialog->config['action']['sections']
                ?? $currentDialog->config['sections']
                ?? [];

            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['goTo'])) {
                        $dialog = $version->dialogs()->where('config->id', $row['goTo'])->first();
                        if ($dialog) return $dialog;
                    }
                }
            }

            $targetId = $this->runConfigActions(
                $currentDialog,
                $this->getActionsForSelection($currentDialog, $selectionId),
                $variables
            );
            if ($targetId) {
                return $version->dialogs()->where('config->id', $targetId)->first();
            }
        }

        // ── Fallback: dialog-level config actions → direct goTo ───────────────
        $targetId = $this->runConfigActions(
            $currentDialog,
            $currentDialog->config['actions'] ?? [],
            $variables
        );

        if (!$targetId && !empty($currentDialog->config['goTo'])) {
            $targetId = $currentDialog->config['goTo'];
        }

        return $targetId
            ? $version->dialogs()->where('config->id', $targetId)->first()
            : null;
    }

    /**
     * Get the action list for a specific button/row selection from the dialog config.
     */
    private function getActionsForSelection(Dialog $dialog, string $selectionId): array
    {
        if ($dialog->kind === 'buttons') {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId) {
                    return $btn['actions'] ?? [];
                }
            }
        }

        if ($dialog->kind === 'list') {
            $sections = $dialog->config['action']['sections'] ?? $dialog->config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId) {
                        return $row['actions'] ?? [];
                    }
                }
            }
        }

        return [];
    }

    /**
     * Run an array of action config arrays.
     * Returns the first navigation UUID produced, or null.
     */
    private function runConfigActions(Dialog $dialog, array $actions, array $variables): ?string
    {
        foreach ($actions as $action) {
            if (!is_array($action)) continue;

            $target = $this->actionExecutor->execute(
                $this->conversation,
                $dialog,
                $action,
                $variables
            );

            if ($target) return $target;
        }

        return null;
    }

    // =========================================================================
    // DIALOG EXECUTION (sends the WhatsApp message)
    // =========================================================================

    private function executeDialog(Dialog $dialog): array
    {
        if ($dialog->flow_version_id !== $this->conversation->flow_version_id) {
            Log::error('Version mismatch executing dialog', [
                'dialog_id'       => $dialog->id,
                'dialog_version'  => $dialog->flow_version_id,
                'conv_version'    => $this->conversation->flow_version_id,
            ]);
            return ['success' => false, 'error' => 'Version mismatch'];
        }

        $kind = $dialog->kind;

        Log::info('Executing dialog', [
            'dialog_id'       => $dialog->id,
            'kind'            => $kind,
            'conversation_id' => $this->conversation->id,
        ]);

        return match ($kind) {
            'trigger'  => ['success' => true,  'stop' => false],
            'message'  => $this->executeMessageDialog($dialog),
            'buttons'  => $this->executeButtonsDialog($dialog),
            'list'     => $this->executeListDialog($dialog),
            'media'    => $this->executeMediaDialog($dialog),
            'location' => $this->executeLocationDialog($dialog),
            'contact'  => $this->executeContactDialog($dialog),
            'end'      => $this->executeEndDialog($dialog),
            default    => ['success' => false, 'error' => "Unknown dialog kind: {$kind}"],
        };
    }

    // ── Individual dialog senders ─────────────────────────────────────────────

    private function executeMessageDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $text      = $this->variableResolver->resolve($dialog->config['text'] ?? '', $variables);

        $this->messageService->sendTextMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $text,
            $variables
        );

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        // Stop and wait if collecting free-text input
        $stop = !empty($dialog->config['inputVariable']) || !empty($dialog->input_variable);
        return ['success' => true, 'stop' => $stop];
    }

    private function executeButtonsDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $config    = $dialog->config;

        $text    = $this->variableResolver->resolve($config['btnText'] ?? $config['text'] ?? '', $variables);
        $buttons = array_map(fn($b) => [
            'id'    => $b['id'],
            'title' => $this->variableResolver->resolve($b['label'] ?? $b['title'] ?? '', $variables),
        ], $config['buttons'] ?? []);

        $this->messageService->sendButtonMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $text,
            $buttons,
            null,
            null,
            $variables
        );

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        return ['success' => true, 'stop' => true]; // always wait for selection
    }

    private function executeListDialog(Dialog $dialog): array
    {
        $variables  = $this->getVariables();
        $config     = $dialog->config;

        $header     = $this->variableResolver->resolve($config['listHeader'] ?? '', $variables);
        $body       = $this->variableResolver->resolve($config['listBody']   ?? $config['text'] ?? '', $variables);
        $footer     = $this->variableResolver->resolve($config['listFooter'] ?? '', $variables);
        $buttonText = $this->variableResolver->resolve(
            $config['action']['button'] ?? $config['buttonText'] ?? 'View Options',
            $variables
        );

        $sections = [];
        foreach ($config['action']['sections'] ?? $config['sections'] ?? [] as $section) {
            $rows = [];
            foreach ($section['rows'] ?? [] as $row) {
                $rows[] = [
                    'id'          => $row['id'],
                    'title'       => $this->variableResolver->resolve($row['title']       ?? '', $variables),
                    'description' => $this->variableResolver->resolve($row['description'] ?? '', $variables),
                ];
            }
            $sections[] = [
                'title' => $this->variableResolver->resolve($section['title'] ?? '', $variables),
                'rows'  => $rows,
            ];
        }

        $this->messageService->sendListMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $body,
            $buttonText,
            $sections,
            $header,
            $footer,
            $variables
        );

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        return ['success' => true, 'stop' => true]; // always wait for selection
    }

    private function executeMediaDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $config    = $dialog->config;
        $mediaFile = null;
        $mimeType  = null;
        $url       = '';

        // ── Branch 1: uploaded file → BotMediaFile record ────────────────────
        // MediaNode sets mediaFileId when a file is uploaded. We use the DB record
        // to get the exact URL and mime_type — no guessing needed.
        if (!empty($config['mediaFileId'])) {
            $mediaFile = \App\Models\BotMediaFile::find($config['mediaFileId']);
        }

        if ($mediaFile) {
            $url      = $mediaFile->url;
            $mimeType = $mediaFile->mime_type;

            Log::info('Media dialog: using BotMediaFile record', [
                'dialog_id'     => $dialog->id,
                'media_file_id' => $mediaFile->id,
                'url'           => $url,
                'mime_type'     => $mimeType,
            ]);
        } else {
            // ── Branch 2: URL entered manually in the flow builder ────────────
            $url = $this->variableResolver->resolve($config['mediaUrl'] ?? '', $variables);
        }

        if (empty($url)) {
            Log::warning('Media dialog has no URL', [
                'dialog_id'     => $dialog->id,
                'media_file_id' => $config['mediaFileId'] ?? null,
                'media_url_raw' => $config['mediaUrl'] ?? null,
            ]);
            return ['success' => false, 'error' => 'Media URL is required'];
        }

        // Ensure fully-qualified URL (uploaded files already have APP_URL prefix)
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Log::error('Media dialog: invalid URL', ['dialog_id' => $dialog->id, 'url' => $url]);
            return ['success' => false, 'error' => 'Invalid media URL.'];
        }

        // ── Decide: send as media file OR as a plain text link ───────────────
        //
        // WhatsApp media messages require an actual downloadable file. If the URL
        // has no recognisable media file extension it is almost certainly a webpage
        // (YouTube, Vimeo, a product page, etc.) — WhatsApp will accept the API
        // call but then report status=failed when it tries to fetch the content.
        //
        // Rule:
        //   - Uploaded file (mediaFileId set)   → always send as media (mime_type known)
        //   - URL with a media file extension   → send as media
        //   - URL with no / non-media extension → send as text message containing the link
        //
        $mediaType = $config['mediaType'] ?? 'image';
        $caption   = $this->variableResolver->resolve($config['mediaCaption']  ?? '', $variables);
        $filename  = $this->variableResolver->resolve($config['mediaFilename'] ?? '', $variables);
        $stop      = !empty($config['waitForReply']);

        $isUploadedFile = $mediaFile !== null;

        if (!$isUploadedFile && !$this->urlLooksLikeMediaFile($url)) {
            // ── Link mode: send as a text message so it renders as a clickable link ──
            Log::info('Media dialog: URL has no media extension — sending as text link', [
                'dialog_id' => $dialog->id,
                'url'       => $url,
            ]);

            // Compose: optional caption on first line, then the link
            $body = trim(($caption ? $caption . "\n" : '') . $url);

            $this->messageService->sendTextMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $body,
                $variables
            );

            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => $stop];
        }

        // ── Media mode: URL points to an actual file ──────────────────────────
        //
        // mime_type rules:
        //   - Uploaded file  → exact mime_type from BotMediaFile record
        //   - URL, document  → infer from extension (WA needs it for the viewer)
        //   - URL, image/video/audio → null (WA infers from Content-Type header;
        //     sending mime_type on these causes "(#100) Unexpected key mime_type")
        if (!$isUploadedFile && $mediaType === 'document') {
            $mimeType = $this->inferMimeType($url, $mediaType);
        }

        Log::info('Media dialog: sending as media', [
            'dialog_id'  => $dialog->id,
            'media_type' => $mediaType,
            'url'        => $url,
            'mime_type'  => $mimeType,
        ]);

        try {
            $this->messageService->sendMediaMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $mediaType,
                $url,
                $caption,
                $filename,
                $variables,
                $mimeType
            );

            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => $stop];
        } catch (\Exception $e) {
            Log::error('Failed to send media dialog', [
                'dialog_id' => $dialog->id,
                'url'       => $url,
                'error'     => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Returns true if the URL path ends with a recognised media file extension.
     * Used to decide whether to send a WhatsApp media message or a plain text link.
     *
     * Uploaded files always bypass this check (mime_type is known from the DB).
     */
    private function urlLooksLikeMediaFile(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (empty($ext)) return false;

        $mediaExtensions = [
            // Images
            'jpg',
            'jpeg',
            'png',
            'webp',
            'gif',
            // Video
            'mp4',
            '3gp',
            '3gpp',
            'mov',
            'avi',
            'mkv',
            'webm',
            // Audio
            'mp3',
            'aac',
            'amr',
            'ogg',
            'oga',
            'opus',
            'm4a',
            'wav',
            'flac',
            // Documents
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'zip',
            'csv',
            // Sticker
            'webp',
        ];

        return in_array($ext, $mediaExtensions, true);
    }

    private function executeLocationDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $config    = $dialog->config;
        $lat       = (float) ($config['locationLatitude']  ?? 0);
        $lng       = (float) ($config['locationLongitude'] ?? 0);

        if ($lat == 0 || $lng == 0) {
            return ['success' => false, 'error' => 'Valid coordinates are required'];
        }

        try {
            $this->messageService->sendLocationMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $lat,
                $lng,
                $this->variableResolver->resolve($config['locationName']    ?? '', $variables),
                $this->variableResolver->resolve($config['locationAddress'] ?? '', $variables)
            );

            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send location dialog', ['dialog_id' => $dialog->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeContactDialog(Dialog $dialog): array
    {
        $variables   = $this->getVariables();
        $contactData = $dialog->config['contactData'] ?? [];

        if (empty($contactData['name']['formatted_name'])) {
            return ['success' => false, 'error' => 'Contact name is required'];
        }

        try {
            $this->messageService->sendContactMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $this->resolveContactVariables($contactData, $variables)
            );

            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send contact dialog', ['dialog_id' => $dialog->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeEndDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();

        if (!empty($dialog->config['text'])) {
            $this->messageService->sendTextMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $this->variableResolver->resolve($dialog->config['text'], $variables),
                $variables
            );
            $this->stampDialog($dialog);
        }

        $this->conversation->update(['status' => 'completed', 'ended_at' => now()]);
        $this->logAnalytics($dialog, 'dialog_completed');
        $this->logAnalytics($dialog, 'conversation_completed');

        return ['success' => true, 'stop' => true];
    }

    // =========================================================================
    // USER INPUT PROCESSING
    // =========================================================================

    /**
     * Persist the user's reply into conversation variables.
     * Uses ConversationVariable (key/value) — NOT $conversation->setVariable().
     */
    private function processUserInput(Dialog $dialog, Message $message): void
    {
        $config = $dialog->config ?? [];
        $kind   = $dialog->kind;

        $inputValue = match ($message->message_type) {
            'text'        => $message->content['text'] ?? '',
            'interactive' => $message->content['response']['title']
                ?? $message->content['response']['id']
                ?? '',
            default       => '',
        };

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id'] ?? null,
            'text'        => $message->content['text']           ?? null,
            default       => null,
        };

        // Free-text variable capture (message / input nodes)
        $inputVar = $config['inputVariable'] ?? $dialog->input_variable ?? null;
        if ($inputVar && $inputValue !== '') {
            $this->setVariable($inputVar, $inputValue);
        }

        // Button saveVariable — save the label of the tapped button
        if ($kind === 'buttons' && $selectionId) {
            foreach ($config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['saveVariable'])) {
                    $this->setVariable($btn['saveVariable'], $btn['label'] ?? $btn['title'] ?? '');
                }
            }
        }

        // List row saveVariable — save the title of the selected row
        if ($kind === 'list' && $selectionId) {
            $sections = $config['action']['sections'] ?? $config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['saveVariable'])) {
                        $this->setVariable($row['saveVariable'], $row['title'] ?? '');
                    }
                }
            }
        }

        // Log completion for analytics
        $this->logAnalytics($dialog, 'dialog_completed');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Set a conversation variable.
     * ConversationVariable stores key/value pairs linked to the conversation.
     * $conversation->setVariable() does NOT exist on the model.
     */
    public function setVariable(string $key, mixed $value): void
    {
        ConversationVariable::updateOrCreate(
            [
                'conversation_id' => $this->conversation->id,
                'key'             => $key,
            ],
            ['value' => is_array($value) ? json_encode($value) : (string) $value]
        );
    }

    /**
     * Get all conversation variables as key → value array.
     */
    private function getVariables(): array
    {
        return $this->conversation->variables()->pluck('value', 'key')->toArray();
    }

    /**
     * Get the current dialog by reading ConversationContext.last_dialog_id.
     * Message.flow_node_id does NOT exist in the new schema.
     */
    private function getCurrentDialog($version, Conversation $conversation): ?Dialog
    {
        $context = $conversation->context;

        if (!$context || !$context->last_dialog_id) {
            return null;
        }

        return $version->dialogs()->find($context->last_dialog_id);
    }

    /**
     * Record that the conversation is currently at this dialog.
     * Uses ConversationContext.last_dialog_id — Message has no dialog FK column.
     *
     * 'variables' is provided on every upsert so the initial INSERT satisfies
     * the NOT NULL column. Run migration 000030 to make it nullable as well.
     */
    private function stampDialog(Dialog $dialog): void
    {
        ConversationContext::updateOrCreate(
            ['conversation_id' => $this->conversation->id],
            [
                'last_dialog_id' => $dialog->id,
                'expires_at'     => now()->addHours(24),
                'variables'      => $this->getVariables(),
            ]
        );
    }

    /**
     * Log an analytics event.
     * AnalyticsEvent has NO node_id column — dialog reference goes in metadata JSON.
     */
    private function logAnalytics(Dialog $dialog, string $eventType, array $extra = []): void
    {
        try {
            AnalyticsEvent::create([
                'tenant_id'       => $this->conversation->tenant_id,
                'flow_id'         => $this->conversation->flow_id,
                'conversation_id' => $this->conversation->id,
                'event_type'      => $eventType,
                'metadata'        => array_merge([
                    'dialog_id'   => $dialog->id,
                    'dialog_kind' => $dialog->kind,
                ], $extra),
            ]);
        } catch (\Exception $e) {
            // Non-fatal — never let analytics break the conversation
            Log::warning('Analytics event logging failed', [
                'event_type' => $eventType,
                'dialog_id'  => $dialog->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function resolveContactVariables(array $contactData, array $variables): array
    {
        $resolve = fn($v) => $this->variableResolver->resolve((string) $v, $variables);

        if (isset($contactData['name'])) {
            $contactData['name'] = array_map($resolve, $contactData['name']);
        }

        foreach (['phones', 'emails', 'addresses', 'urls'] as $field) {
            if (!isset($contactData[$field])) continue;
            foreach ($contactData[$field] as $i => $item) {
                $contactData[$field][$i] = array_map(
                    fn($v) => is_string($v) ? $resolve($v) : $v,
                    $item
                );
            }
        }

        if (isset($contactData['org'])) {
            $contactData['org'] = array_map($resolve, $contactData['org']);
        }

        return $contactData;
    }

    /**
     * Infer a MIME type from a URL's file extension.
     *
     * WhatsApp requires the media object to include a correct mime_type when
     * it cannot be determined from the response headers (CDN URLs, short links,
     * redirects, etc.). Without it, WhatsApp may accept the API call (200 OK)
     * but then fail to deliver the message (status → "failed").
     *
     * Falls back to sensible defaults per mediaType when the extension is
     * unrecognised or missing.
     */
    private function inferMimeType(string $url, string $mediaType): string
    {
        // Extract the path component and get the lowercase extension
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $map = [
            // Images
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            // Video
            'mp4'  => 'video/mp4',
            '3gp'  => 'video/3gpp',
            '3gpp' => 'video/3gpp',
            'mov'  => 'video/quicktime',
            // Audio
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'opus' => 'audio/ogg; codecs=opus',
            'mp3'  => 'audio/mpeg',
            'aac'  => 'audio/aac',
            'amr'  => 'audio/amr',
            'm4a'  => 'audio/mp4',
            // Documents
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain',
        ];

        if (!empty($ext) && isset($map[$ext])) {
            return $map[$ext];
        }

        // No extension in URL — fall back to the most common type for this category
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
