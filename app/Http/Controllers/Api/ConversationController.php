<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    /**
     * List conversations
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Conversation::where('tenant_id', $tenant->id)
            ->with(['chatbot', 'whatsappAccount', 'assignedAgent']);

        // Search by phone or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('whatsapp_user_phone', 'like', "%{$search}%")
                    ->orWhere('whatsapp_user_name', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by chatbot
        if ($request->has('chatbot_id')) {
            $query->where('chatbot_id', $request->chatbot_id);
        }

        // Filter by WhatsApp account
        if ($request->has('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        // Filter by assigned agent
        if ($request->has('assigned_agent_id')) {
            if ($request->assigned_agent_id === 'unassigned') {
                $query->whereNull('assigned_agent_id');
            } else {
                $query->where('assigned_agent_id', $request->assigned_agent_id);
            }
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('started_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('started_at', '<=', $request->end_date);
        }

        // Sorting
        $sortField = $request->get('sort', 'last_message_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $conversations = $query->paginate($request->get('per_page', 20));

        // Add duration for each conversation
        $conversations->getCollection()->transform(function ($conversation) {
            $conversation->duration_formatted = $conversation->getFormattedDuration();
            return $conversation;
        });

        return response()->json($conversations);
    }

    /**
     * Get single conversation
     */
    public function show(Conversation $conversation): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure conversation belongs to current tenant
        if ($conversation->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        $conversation->load([
            'chatbot',
            'whatsappAccount',
            'assignedAgent',
            'context',
        ]);

        return response()->json([
            'conversation' => $conversation,
            'duration_formatted' => $conversation->getFormattedDuration(),
        ]);
    }

    /**
     * Get conversation messages
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure conversation belongs to current tenant
        if ($conversation->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        $query = $conversation->messages()->orderBy('sent_at', 'asc');

        // Filter by direction
        if ($request->has('direction')) {
            $query->where('direction', $request->direction);
        }

        // Filter by message type
        if ($request->has('message_type')) {
            $query->where('message_type', $request->message_type);
        }

        $messages = $query->paginate($request->get('per_page', 50));

        return response()->json($messages);
    }

    /**
     * Handoff conversation to agent
     */
    public function handoff(Request $request, Conversation $conversation): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure conversation belongs to current tenant
        if ($conversation->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        $validated = $request->validate([
            'assigned_agent_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string',
        ]);

        $conversation->handoff($validated['assigned_agent_id'] ?? null);

        // Send handoff message to user
        if ($conversation->chatbot) {
            $messageService = app(\App\Services\WhatsAppMessageService::class);
            $messageService->sendTextMessage(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $validated['reason'] ?? 'Transferring you to an agent...'
            );
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($conversation)
            ->withProperties($validated)
            ->log('Conversation handed off');

        return response()->json([
            'message' => 'Conversation handed off successfully',
            'conversation' => $conversation->fresh(),
        ]);
    }

    /**
     * End conversation
     */
    public function end(Conversation $conversation): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure conversation belongs to current tenant
        if ($conversation->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        $conversation->complete();

        activity()
            ->causedBy(auth()->user())
            ->performedOn($conversation)
            ->log('Conversation ended');

        return response()->json([
            'message' => 'Conversation ended successfully',
            'conversation' => $conversation,
        ]);
    }

    /**
     * Delete conversation
     */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure conversation belongs to current tenant
        if ($conversation->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        // Don't allow deletion of active conversations
        if ($conversation->isActive()) {
            return response()->json([
                'message' => 'Cannot delete active conversation',
            ], 422);
        }

        $conversation->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Conversation deleted');

        return response()->json([
            'message' => 'Conversation deleted successfully',
        ]);
    }

    /**
     * Export conversations
     */
    public function export(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'format' => 'required|in:csv,json',
            'chatbot_id' => 'nullable|exists:chatbots,id',
            'status' => 'nullable|in:active,completed,abandoned,handed_off',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $query = Conversation::where('tenant_id', $tenant->id);

        if (isset($validated['chatbot_id'])) {
            $query->where('chatbot_id', $validated['chatbot_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['start_date'])) {
            $query->whereDate('started_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->whereDate('started_at', '<=', $validated['end_date']);
        }

        $conversations = $query->with(['chatbot', 'whatsappAccount'])->get();

        if ($validated['format'] === 'csv') {
            $csv = $this->generateCSV($conversations);

            return response()->json([
                'data' => base64_encode($csv),
                'filename' => 'conversations_' . now()->format('Y-m-d') . '.csv',
            ]);
        }

        return response()->json([
            'data' => $conversations,
            'filename' => 'conversations_' . now()->format('Y-m-d') . '.json',
        ]);
    }

    /**
     * Generate CSV from conversations
     */
    private function generateCSV($conversations): string
    {
        $csv = "ID,Phone,Name,Chatbot,Status,Started At,Ended At,Duration,Message Count\n";

        foreach ($conversations as $conversation) {
            $csv .= implode(',', [
                $conversation->id,
                $conversation->whatsapp_user_phone,
                $conversation->whatsapp_user_name ?? 'N/A',
                $conversation->chatbot->name ?? 'N/A',
                $conversation->status,
                $conversation->started_at->format('Y-m-d H:i:s'),
                $conversation->ended_at ? $conversation->ended_at->format('Y-m-d H:i:s') : 'N/A',
                $conversation->getFormattedDuration() ?? 'N/A',
                $conversation->message_count,
            ]) . "\n";
        }

        return $csv;
    }

    /**
     * Get conversation statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Conversation::where('tenant_id', $tenant->id);

        // Apply filters
        if ($request->has('chatbot_id')) {
            $query->where('chatbot_id', $request->chatbot_id);
        }

        if ($request->has('start_date')) {
            $query->whereDate('started_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('started_at', '<=', $request->end_date);
        }

        $stats = [
            'total_conversations' => $query->count(),
            'active_conversations' => (clone $query)->where('status', 'active')->count(),
            'completed_conversations' => (clone $query)->where('status', 'completed')->count(),
            'abandoned_conversations' => (clone $query)->where('status', 'abandoned')->count(),
            'handed_off_conversations' => (clone $query)->where('status', 'handed_off')->count(),
            'average_duration_seconds' => (clone $query)->whereNotNull('ended_at')
                ->get()
                ->avg(fn($c) => $c->getDuration()),
            'average_message_count' => (clone $query)->avg('message_count'),
            'by_status' => (clone $query)->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ];

        return response()->json($stats);
    }
}