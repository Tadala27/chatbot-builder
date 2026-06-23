<?php

namespace App\Services\Flow;

use App\Models\Action;
use App\Models\Conversation;
use App\Models\Dialog;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\ActionExecutorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NavigationResolver
{

    private ?string $pendingNavigationTarget = null;

    public function __construct(
        private ActionExecutorService $actionExecutor,
    ) {}

    public function setPendingNavigation(?string $target): void
    {
        $this->pendingNavigationTarget = $target;
    }

    public function consumePendingNavigation(): ?string
    {
        $target = $this->pendingNavigationTarget;
        $this->pendingNavigationTarget = null;
        return $target;
    }

    public function findDialogOwningSelection(FlowVersion $version, string $selectionId): ?Dialog
    {
        $dialogs = $version->dialogs()
            ->whereIn('kind', ['buttons', 'list', 'nav_buttons'])
            ->get();

        foreach ($dialogs as $dialog) {
            if ($this->dialogContainsSelection($dialog, $selectionId)) {
                return $dialog;
            }
        }

        return null;
    }

    private function dialogContainsSelection(Dialog $dialog, string $selectionId): bool
    {
        if (in_array($dialog->kind, ['buttons', 'nav_buttons'], true)) {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId) return true;
            }
            return false;
        }

        if ($dialog->kind === 'list') {
            $sections = $dialog->config['action']['sections'] ?? $dialog->config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId) return true;
                }
            }
        }

        return false;
    }

    public function findDialogByConfigId(FlowVersion $version, string $configId): ?Dialog
    {
        $query = $version->dialogs();

        if (Schema::hasColumn('dialogs', 'config_id')) {
            return $query->where('config_id', $configId)->first();
        }

        return $query->where('config->id', $configId)->first();
    }


    public function resolveFromMessage(
        FlowVersion  $version,
        Dialog       $currentDialog,
        Message      $message,
        Conversation $conversation,
        array        $variables,
        array        $dialogActions,
        bool         $actionsAlreadyRan = false
    ): ?Dialog {
        $kind = $currentDialog->kind;

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id'] ?? null,
            'text'        => $message->content['text']           ?? null,
            default       => null,
        };

        // Buttons / nav_buttons / list — shared resolver
        if (in_array($kind, ['buttons', 'nav_buttons', 'list'], true) && $selectionId) {
            $target = $this->resolveSelectionNavigation(
                $conversation,
                $currentDialog,
                $selectionId,
                $variables,
                $dialogActions
            );
            if ($target) {
                return $this->findDialogByConfigId($version, $target);
            }
        }

        // Text input — either consume pending target or run actions
        if ($actionsAlreadyRan) {
            $targetId = $this->consumePendingNavigation();

            Log::info('Using pending navigation target', [
                'conversation_id' => $conversation->id,
                'target_id'       => $targetId,
            ]);
        } else {
            $targetId = $this->runActionChain($conversation, $currentDialog, $dialogActions, $variables);
        }

        if (!$targetId && !empty($currentDialog->config['goTo'])) {
            $targetId = $currentDialog->config['goTo'];
        }

        return $targetId ? $this->findDialogByConfigId($version, $targetId) : null;
    }


    public function resolveSelectionNavigation(
        Conversation $conversation,
        Dialog       $dialog,
        string       $selectionId,
        array        $variables,
        array        $dialogActions
    ): ?string {
        // 1. Direct goTo on the button/row
        if ($goTo = $this->getSelectionGoTo($dialog, $selectionId)) {
            return $goTo;
        }

        // 2. Inline actions on the button/row
        $selectionActions = $this->getActionsForSelection($dialog, $selectionId);
        if (!empty($selectionActions)) {
            $target = $this->runActionChain($conversation, $dialog, $selectionActions, $variables);
            if ($target) return $target;
        }

        // 3. Dialog-level DB actions
        return $this->runActionChain($conversation, $dialog, $dialogActions, $variables);
    }

    public function getSelectionGoTo(Dialog $dialog, string $selectionId): ?string
    {
        if (in_array($dialog->kind, ['buttons', 'nav_buttons'], true)) {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['goTo'])) {
                    return $btn['goTo'];
                }
            }
            return null;
        }

        if ($dialog->kind === 'list') {
            $sections = $dialog->config['action']['sections'] ?? $dialog->config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['goTo'])) {
                        return $row['goTo'];
                    }
                }
            }
        }

        return null;
    }

    public function getActionsForSelection(Dialog $dialog, string $selectionId): array
    {
        if (in_array($dialog->kind, ['buttons', 'nav_buttons'], true)) {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId) {
                    return $btn['actions'] ?? [];
                }
            }
            return [];
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

    public function runActionChain(
        Conversation $conversation,
        Dialog       $dialog,
        array        $actions,
        array        $variables
    ): ?string {
        foreach ($actions as $action) {
            if (!is_array($action)) continue;

            $result = $this->executeSingleAction($conversation, $dialog, $action, $variables);

            // Re-fetch variables after each action (actions can mutate state)
            $variables = $conversation->variables()->pluck('value', 'key')->toArray();

            if ($result !== null && !str_starts_with((string) $result, '__system:')) {
                return $result;
            }
        }

        return null;
    }

    private function executeSingleAction(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        $target = $this->actionExecutor->execute($conversation, $dialog, $action, $variables);

        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        if ($target !== null && !str_starts_with((string) $target, '__system:')) {
            return $target;
        }

        $thenAction = $this->resolveThenAction($dialog, $action);
        if ($thenAction !== null) {
            return $this->executeSingleAction($conversation, $dialog, $thenAction, $variables);
        }

        return $target;
    }

    private function resolveThenAction(Dialog $dialog, array $action): ?array
    {
        if (!empty($action['then']) && is_array($action['then'])) {
            return $action['then'];
        }

        $actionId = $action['_db_action_id'] ?? null;
        if ($actionId) {
            $thenAction = Action::find($actionId)?->thenAction;
            if ($thenAction) {
                $config = is_array($thenAction->config)
                    ? $thenAction->config
                    : (json_decode($thenAction->config, true) ?? []);
                return array_merge($config, [
                    'kind'          => $thenAction->action_type,
                    '_db_action_id' => $thenAction->then_action_id,
                ]);
            }
        }

        return null;
    }
}