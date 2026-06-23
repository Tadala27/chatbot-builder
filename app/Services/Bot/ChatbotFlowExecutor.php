<?php

namespace App\Services\Bot;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Dialog;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Flow\ConversationStateManager;
use App\Services\Flow\DialogRenderer;
use App\Services\Flow\NavigationResolver;
use App\Services\Flow\SystemActionHandler;
use Illuminate\Support\Facades\Log;

class ChatbotFlowExecutor
{
    public function __construct(
        private DialogRenderer           $renderer,
        private NavigationResolver       $navigator,
        private ConversationStateManager $state,
        private SystemActionHandler      $systemActions,
    ) {}


    public function processMessage(Conversation $conversation, Message $message): void
    {
        $this->navigator->setPendingNavigation(null);

        try {
            $flow    = $conversation->flow;
            $version = $conversation->flowVersion;

            if (!$flow || $flow->status !== 'published' || !$version) {
                Log::warning('Flow not available', [
                    'conversation_id' => $conversation->id,
                    'flow_status'     => $flow?->status,
                ]);
                return;
            }

            // Do interactive lookup once
            $ownerDialog = null;
            $selectionId = null;
            if ($message->message_type === 'interactive') {
                $selectionId = $message->content['response']['id'] ?? null;
                if ($selectionId) {
                    $ownerDialog = $this->navigator->findDialogOwningSelection($version, $selectionId);
                }
            }

            // 1. System action intercept
            if ($ownerDialog && $selectionId) {
                $sysKind = $this->systemActions->detectSystemAction($ownerDialog, $selectionId);
                if ($sysKind !== null) {
                    $this->logAnalytics($conversation, $ownerDialog, "system_action_{$sysKind}");
                    $target = $this->systemActions->execute($sysKind, $conversation, $ownerDialog, $version);
                    if ($target) {
                        $this->executeDialogFlow($target, $conversation);
                    }
                    return;
                }
            }

            $currentDialog = $this->state->getCurrentDialog($version, $conversation);

            // 2. Late-selection intercept
            if ($ownerDialog && (!$currentDialog || $ownerDialog->id !== $currentDialog->id)) {
                Log::info('Late-selection intercept', [
                    'conversation_id' => $conversation->id,
                    'owner_dialog_id' => $ownerDialog->id,
                ]);
                $this->handleDialogResponse($ownerDialog, $message, $version, $conversation);
                return;
            }

            // 3. Normal flow
            if ($currentDialog) {
                $this->handleDialogResponse($currentDialog, $message, $version, $conversation);
            } else {
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


    public function executeDialogFlow(Dialog $dialog, ?Conversation $conversation = null): void
    {
        $conversation ??= $dialog->flowVersion->dialogs->first()?->conversation;
        if (!$conversation) {
            throw new \RuntimeException('executeDialogFlow requires a conversation');
        }

        try {
            $version = $conversation->flowVersion;
            if (!$version) return;

            $result = $this->executeDialog($dialog, $conversation);

            if ($result['stop'] ?? false) {
                return;
            }

            if ($result['success'] ?? false) {
                $this->continueFromDialog($dialog, $conversation, $version);
            }
        } catch (\Exception $e) {
            Log::error('Error executing dialog flow', [
                'conversation_id' => $conversation->id,
                'dialog_id'       => $dialog->id,
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    private function handleDialogResponse(
        Dialog       $dialog,
        Message      $message,
        FlowVersion  $version,
        Conversation $conversation
    ): void {
        $actionsAlreadyRan = $this->processUserInput($dialog, $message, $conversation);
        $variables         = $this->state->getVariables($conversation);
        $dialogActions     = $this->getDialogActions($dialog);

        $nextDialog = $this->navigator->resolveFromMessage(
            $version,
            $dialog,
            $message,
            $conversation,
            $variables,
            $dialogActions,
            $actionsAlreadyRan
        );

        if ($nextDialog) {
            $this->executeDialogFlow($nextDialog, $conversation);
        } else {
            Log::warning('No next dialog after user input', [
                'conversation_id' => $conversation->id,
                'dialog_id'       => $dialog->id,
            ]);
        }
    }

    private function continueFromDialog(Dialog $dialog, Conversation $conversation, FlowVersion $version): void
    {
        $variables     = $this->state->getVariables($conversation);
        $dialogActions = $this->getDialogActions($dialog);

        $nextDialogId = $this->navigator->runActionChain($conversation, $dialog, $dialogActions, $variables);

        if (!$nextDialogId && !empty($dialog->config['goTo'])) {
            $nextDialogId = $dialog->config['goTo'];
        }

        if (!$nextDialogId) return;

        $nextDialog = $this->navigator->findDialogByConfigId($version, $nextDialogId);
        if ($nextDialog) {
            $this->executeDialogFlow($nextDialog, $conversation);
        } else {
            Log::warning('Next dialog config.id not found', [
                'dialog_id'      => $dialog->id,
                'next_dialog_id' => $nextDialogId,
            ]);
        }
    }

    private function startFlow(Conversation $conversation): void
    {
        $startDialog = $conversation->flowVersion
            ?->dialogs()
            ->where('is_entry_point', true)
            ->first();

        if (!$startDialog) {
            Log::warning('No entry point dialog', ['conversation_id' => $conversation->id]);
            return;
        }

        $this->executeDialogFlow($startDialog, $conversation);
    }

    // =========================================================================
    // DIALOG EXECUTION
    // =========================================================================

    private function executeDialog(Dialog $dialog, Conversation $conversation): array
    {
        if ($dialog->flow_version_id !== $conversation->flow_version_id) {
            Log::error('Version mismatch executing dialog', [
                'dialog_id'      => $dialog->id,
                'dialog_version' => $dialog->flow_version_id,
                'conv_version'   => $conversation->flow_version_id,
            ]);
            return ['success' => false, 'error' => 'Version mismatch'];
        }

        $variables = $this->state->getVariables($conversation);
        $result    = $this->renderer->render($dialog, $conversation, $variables);

        if ($result['success'] ?? false) {
            $this->state->stampDialog($conversation, $dialog);
            $this->logAnalytics($conversation, $dialog, 'dialog_entered');

            // If this is a text-input message dialog with a variable action,
            // override stop to true so we wait for the user's response.
            if ($dialog->kind === 'message') {
                $hasVariableAction = collect($this->getDialogActions($dialog))
                    ->contains(fn($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');
                if ($hasVariableAction) {
                    $result['stop'] = true;
                }
            }

            if ($dialog->kind === 'end') {
                $conversation->update(['status' => 'completed', 'ended_at' => now()]);
                $this->logAnalytics($conversation, $dialog, 'dialog_completed');
                $this->logAnalytics($conversation, $dialog, 'conversation_completed');
            }
        }

        return $result;
    }


    private function processUserInput(
        Dialog       $dialog,
        Message      $message,
        Conversation $conversation
    ): bool {
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

        // 1. Save inputVariable
        $inputVar = $config['inputVariable'] ?? $dialog->input_variable ?? null;
        if ($inputVar && $inputValue !== '') {
            $this->state->setVariable($conversation, $inputVar, $inputValue);
        }

        // 2. Save button/row-level saveVariable
        if ($selectionId) {
            $this->saveSelectionVariables($dialog, $selectionId, $conversation);
        }

        // 3. Save save_response DialogOption selection
        if (in_array($kind, ['buttons', 'list'], true) && $selectionId) {
            $option = $dialog->options()->where('external_id', $selectionId)->first();
            if ($option && $option->save_response) {
                $this->state->saveOptionSelection(
                    $conversation,
                    $dialog->id,
                    $selectionId,
                    $option->title ?? ''
                );
            }
        }

        // 4. Run dialog-level actions for text-input dialogs
        $dialogActions     = $this->getDialogActions($dialog);
        $hasVariableAction = collect($dialogActions)
            ->contains(fn($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');
        $isTextReply = $message->message_type === 'text';

        if (
            $isTextReply &&
            ($inputVar || $hasVariableAction) &&
            !in_array($kind, ['buttons', 'list', 'nav_buttons'], true)
        ) {
            $variables = $this->state->getVariables($conversation);

            // Inject current input so variable action uses THIS message's value
            $actionsWithInput = array_map(function ($action) use ($inputValue) {
                if (($action['kind'] ?? $action['action_type'] ?? null) === 'variable') {
                    $action['_resolvedInput'] = $inputValue;
                }
                return $action;
            }, $dialogActions);

            $target = $this->navigator->runActionChain(
                $conversation,
                $dialog,
                $actionsWithInput,
                $variables
            );

            if ($target) {
                $this->navigator->setPendingNavigation($target);
            }

            $this->logAnalytics($conversation, $dialog, 'dialog_completed');
            return true;
        }

        $this->logAnalytics($conversation, $dialog, 'dialog_completed');
        return false;
    }

    private function saveSelectionVariables(Dialog $dialog, string $selectionId, Conversation $conversation): void
    {
        $config = $dialog->config;

        if ($dialog->kind === 'buttons') {
            foreach ($config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['saveVariable'])) {
                    $this->state->setVariable(
                        $conversation,
                        $btn['saveVariable'],
                        $btn['label'] ?? $btn['title'] ?? ''
                    );
                }
            }
        }

        if ($dialog->kind === 'list') {
            $sections = $config['action']['sections'] ?? $config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['saveVariable'])) {
                        $this->state->setVariable($conversation, $row['saveVariable'], $row['title'] ?? '');
                    }
                }
            }
        }
    }

    private function getDialogActions(Dialog $dialog): array
    {
        return $dialog->actions()
            ->with(['conditions.dialogOption', 'thenAction'])
            ->where('is_active', true)
            ->orderBy('action_order')
            ->get()
            ->map(function ($a) {
                $config = is_array($a->config) ? $a->config : (json_decode($a->config, true) ?? []);

                if ($a->action_type === 'condition' && $a->conditions->isNotEmpty()) {
                    $branchCount = count($config['branches'] ?? []);
                    if ($branchCount <= 1) {
                        $config['_db_conditions'] = $a->conditions->map(fn($c) => [
                            'type'            => $c->condition_type,
                            'operator'        => $c->condition_operator,
                            'source'          => $c->variable_key ?? $c->option_id,
                            'value'           => $c->condition_value,
                            'option_id'       => $c->option_id,
                            'response_field'  => $c->response_field,
                            'response_path'   => $c->response_path,
                            'condition_order' => $c->condition_order,
                        ])->toArray();
                    }
                }

                $config['_db_action_id'] = $a->then_action_id;
                return array_merge($config, ['kind' => $a->action_type]);
            })
            ->toArray();
    }

    private function logAnalytics(Conversation $conversation, Dialog $dialog, string $eventType, array $extra = []): void
    {
        try {
            AnalyticsEvent::create([
                'tenant_id'       => $conversation->tenant_id,
                'flow_id'         => $conversation->flow_id,
                'conversation_id' => $conversation->id,
                'event_type'      => $eventType,
                'metadata'        => array_merge([
                    'dialog_id'   => $dialog->id,
                    'dialog_kind' => $dialog->kind,
                ], $extra),
            ]);
        } catch (\Exception $e) {
            Log::warning('Analytics event logging failed', [
                'event_type' => $eventType,
                'dialog_id'  => $dialog->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}