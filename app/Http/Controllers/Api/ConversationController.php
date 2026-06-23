<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    // GET /api/conversations
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Conversation::where('tenant_id', $tenant->id)
            ->with(['flow.bot', 'whatsappAccount', 'assignedAgent']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('whatsapp_user_phone', 'like', "%{$s}%")
                ->orWhere('whatsapp_user_name', 'like', "%{$s}%"));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('flow_id')) {
            $query->where('flow_id', $request->flow_id);
        }

        if ($request->filled('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        if ($request->filled('assigned_agent_id')) {
            $request->assigned_agent_id === 'unassigned'
                ? $query->whereNull('assigned_agent_id')
                : $query->where('assigned_agent_id', $request->assigned_agent_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('started_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('started_at', '<=', $request->end_date);
        }

        $query->orderBy($request->get('sort', 'last_message_at'), $request->get('direction', 'desc'));

        $conversations = $query->paginate($request->get('per_page', 20));

        $conversations->getCollection()->transform(function ($c) {
            $c->duration_formatted = $c->getFormattedDuration();
            return $c;
        });

        return response()->json($conversations);
    }

    // GET /api/conversations/{conversation}
    public function show(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $conversation->load(['flow.bot', 'whatsappAccount', 'assignedAgent', 'context']);

        return response()->json([
            'conversation'      => $conversation,
            'duration_formatted'=> $conversation->getFormattedDuration(),
        ]);
    }

    // GET /api/conversations/{conversation}/messages
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $query = $conversation->messages()->orderBy('sent_at', 'asc');

        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->filled('message_type')) {
            $query->where('message_type', $request->message_type);
        }

        return response()->json($query->paginate($request->get('per_page', 50)));
    }

    // POST /api/conversations/{conversation}/handoff
    public function handoff(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $validated = $request->validate([
            'assigned_agent_id' => 'nullable|exists:users,id',
            'reason'            => 'nullable|string|max:500',
        ]);

        $conversation->handoff($validated['assigned_agent_id'] ?? null);

        // Notify the WhatsApp user
        if ($conversation->whatsappAccount) {
            app(\App\Services\Bot\WhatsAppMessageService::class)->sendTextMessage(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $validated['reason'] ?? 'Transferring you to an agent...'
            );
        }

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->withProperties($validated)->log('Conversation handed off');

        return response()->json([
            'message'      => 'Conversation handed off.',
            'conversation' => $conversation->fresh(),
        ]);
    }

    // POST /api/conversations/{conversation}/end
    public function end(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $conversation->complete();

        activity()->causedBy(auth()->user())->performedOn($conversation)->log('Conversation ended');

        return response()->json(['message' => 'Conversation ended.', 'conversation' => $conversation]);
    }

    // DELETE /api/conversations/{conversation}
    public function destroy(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        if ($conversation->isActive()) {
            return response()->json(['message' => 'Cannot delete an active conversation.'], 422);
        }

        $conversation->delete();

        activity()->causedBy(auth()->user())->log('Conversation deleted');

        return response()->json(['message' => 'Conversation deleted.']);
    }

    // GET /api/conversations/export
    public function export(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'format'     => 'required|in:csv,json',
            'flow_id'    => 'nullable|exists:flows,id',
            'status'     => 'nullable|in:active,completed,abandoned,handed_off',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $query = Conversation::where('tenant_id', $tenant->id);

        if (!empty($validated['flow_id']))    $query->where('flow_id', $validated['flow_id']);
        if (!empty($validated['status']))     $query->where('status', $validated['status']);
        if (!empty($validated['start_date'])) $query->whereDate('started_at', '>=', $validated['start_date']);
        if (!empty($validated['end_date']))   $query->whereDate('started_at', '<=', $validated['end_date']);

        $conversations = $query->with(['flow.bot', 'whatsappAccount'])->get();

        $filename = 'conversations_' . now()->format('Y-m-d');

        if ($validated['format'] === 'csv') {
            return response()->json([
                'data'     => base64_encode($this->generateCsv($conversations)),
                'filename' => $filename . '.csv',
            ]);
        }

        return response()->json(['data' => $conversations, 'filename' => $filename . '.json']);
    }

    // GET /api/conversations/statistics
    public function statistics(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Conversation::where('tenant_id', $tenant->id);

        if ($request->filled('flow_id'))    $query->where('flow_id', $request->flow_id);
        if ($request->filled('start_date')) $query->whereDate('started_at', '>=', $request->start_date);
        if ($request->filled('end_date'))   $query->whereDate('started_at', '<=', $request->end_date);

        return response()->json([
            'total_conversations'      => (clone $query)->count(),
            'active_conversations'     => (clone $query)->where('status', 'active')->count(),
            'completed_conversations'  => (clone $query)->where('status', 'completed')->count(),
            'abandoned_conversations'  => (clone $query)->where('status', 'abandoned')->count(),
            'handed_off_conversations' => (clone $query)->where('status', 'handed_off')->count(),
            'average_duration_seconds' => (clone $query)->whereNotNull('ended_at')
                ->get()->avg(fn($c) => $c->getDuration()),
            'average_message_count'    => (clone $query)->avg('message_count'),
            'by_status'                => (clone $query)
                ->selectRaw('status, COUNT(*) as count')->groupBy('status')->get(),
        ]);
    }

    // -------------------------------------------------------------------------

    private function authorizeConversation(Conversation $conversation): void
    {
        if ($conversation->tenant_id !== Tenant::current()->id) {
            abort(404, 'Conversation not found.');
        }
    }

    private function generateCsv($conversations): string
    {
        $csv = "ID,Phone,Name,Flow,Bot,Status,Started At,Ended At,Duration (s),Messages\n";

        foreach ($conversations as $c) {
            $csv .= implode(',', [
                $c->id,
                $c->whatsapp_user_phone,
                $c->whatsapp_user_name    ?? 'N/A',
                $c->flow->name            ?? 'N/A',
                $c->flow->bot->name       ?? 'N/A',
                $c->status,
                $c->started_at->format('Y-m-d H:i:s'),
                $c->ended_at?->format('Y-m-d H:i:s') ?? 'N/A',
                $c->getDuration()         ?? 'N/A',
                $c->message_count,
            ]) . "\n";
        }

        return $csv;
    }
}