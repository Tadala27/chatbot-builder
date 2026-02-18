<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Get overview analytics
     */
    public function overview(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $startDate = $request->get('start_date', now()->subDays(30));
        $endDate = $request->get('end_date', now());

        $query = Conversation::where('tenant_id', $tenant->id)
            ->whereBetween('started_at', [$startDate, $endDate]);

        $stats = [
            'total_conversations' => $query->count(),
            'active_conversations' => (clone $query)->where('status', 'active')->count(),
            'completed_conversations' => (clone $query)->where('status', 'completed')->count(),
            'abandoned_conversations' => (clone $query)->where('status', 'abandoned')->count(),
            'handed_off_conversations' => (clone $query)->where('status', 'handed_off')->count(),

            'completion_rate' => $this->calculateCompletionRate($query),
            'average_duration' => $this->calculateAverageDuration($query),
            'average_messages_per_conversation' => (clone $query)->avg('message_count') ?? 0,

            'conversations_by_day' => $this->getConversationsByDay($tenant, $startDate, $endDate),
            'conversations_by_status' => $this->getConversationsByStatus($tenant, $startDate, $endDate),
            'top_chatbots' => $this->getTopChatbots($tenant, $startDate, $endDate),
        ];

        return response()->json($stats);
    }

    /**
     * Get chatbot-specific analytics
     */
    public function chatbot(Request $request, Flow $flow): JsonResponse
    {

        $startDate = $request->get('start_date', now()->subDays(30));
        $endDate = $request->get('end_date', now());

        $query = Conversation::where('flow_id', $flow->id)
            ->whereBetween('started_at', [$startDate, $endDate]);

        $stats = [
            'overview' => [
                'total_conversations' => $query->count(),
                'completed_conversations' => (clone $query)->where('status', 'completed')->count(),
                'abandoned_conversations' => (clone $query)->where('status', 'abandoned')->count(),
                'completion_rate' => $this->calculateCompletionRate($query),
                'average_duration' => $this->calculateAverageDuration($query),
            ],

            'conversations_over_time' => $this->getConversationsByDay($flow->tenant, $startDate, $endDate, $flow->id),

            'node_analytics' => $this->getNodeAnalytics($flow, $startDate, $endDate),

            'popular_paths' => $this->getPopularPaths($flow, $startDate, $endDate),

            'drop_off_points' => $this->getDropOffPoints($flow, $startDate, $endDate),
        ];

        return response()->json($stats);
    }

    /**
     * Get popular conversation paths
     */
    public function popularPaths(Request $request, Flow $flow): JsonResponse
    {

        $startDate = $request->get('start_date', now()->subDays(30));
        $endDate = $request->get('end_date', now());

        $paths = $this->getPopularPaths($flow, $startDate, $endDate);

        return response()->json(['paths' => $paths]);
    }

    /**
     * Get drop-off points
     */
    public function dropOffPoints(Request $request, Flow $flow): JsonResponse
    {

        $startDate = $request->get('start_date', now()->subDays(30));
        $endDate = $request->get('end_date', now());

        $dropOffs = $this->getDropOffPoints($flow, $startDate, $endDate);

        return response()->json(['drop_off_points' => $dropOffs]);
    }

    /**
     * Export analytics data
     */
    public function export(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'format' => 'required|in:csv,json',
            'type' => 'required|in:conversations,analytics_events',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'flow_id' => 'nullable|exists:flows,id',
        ]);

        if ($validated['type'] === 'conversations') {
            $query = Conversation::where('tenant_id', $tenant->id)
                ->whereBetween('started_at', [$validated['start_date'], $validated['end_date']]);

            if (isset($validated['flow_id'])) {
                $query->where('flow_id', $validated['flow_id']);
            }

            $data = $query->with(['flow', 'whatsappAccount'])->get();
        } else {
            $query = AnalyticsEvent::where('tenant_id', $tenant->id)
                ->whereBetween('created_at', [$validated['start_date'], $validated['end_date']]);

            if (isset($validated['flow_id'])) {
                $query->where('flow_id', $validated['flow_id']);
            }

            $data = $query->get();
        }

        if ($validated['format'] === 'csv') {
            $csv = $this->generateCSV($data, $validated['type']);
            return response()->json([
                'data' => base64_encode($csv),
                'filename' => $validated['type'] . '_' . now()->format('Y-m-d') . '.csv',
            ]);
        }

        return response()->json([
            'data' => $data,
            'filename' => $validated['type'] . '_' . now()->format('Y-m-d') . '.json',
        ]);
    }

    // Helper methods

    private function calculateCompletionRate($query): float
    {
        $total = (clone $query)->count();
        if ($total === 0) return 0;

        $completed = (clone $query)->where('status', 'completed')->count();
        return round(($completed / $total) * 100, 2);
    }

    private function calculateAverageDuration($query): ?float
    {
        $conversations = (clone $query)->whereNotNull('ended_at')->get();

        if ($conversations->isEmpty()) return null;

        $totalSeconds = $conversations->sum(fn($c) => $c->getDuration());
        return round($totalSeconds / $conversations->count(), 2);
    }

    private function getConversationsByDay($tenant, $startDate, $endDate, $flowId = null): array
    {
        $query = Conversation::where('tenant_id', $tenant->id)
            ->whereBetween('started_at', [$startDate, $endDate]);

        if ($flowId) {
            $query->where('flow_id', $flowId);
        }

        return $query->selectRaw('DATE(started_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getConversationsByStatus($tenant, $startDate, $endDate): array
    {
        return Conversation::where('tenant_id', $tenant->id)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->toArray();
    }

    private function getTopChatbots($tenant, $startDate, $endDate): array
    {
        return Conversation::where('tenant_id', $tenant->id)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw('flow_id, COUNT(*) as conversation_count')
            ->with('flow:id,name')
            ->groupBy('flow_id')
            ->orderByDesc('conversation_count')
            ->limit(5)
            ->get()
            ->toArray();
    }

    private function getNodeAnalytics($flow, $startDate, $endDate): array
    {
        $events = AnalyticsEvent::where('flow_id', $flow->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('event_type', ['node_entered', 'node_completed'])
            ->selectRaw('node_id, event_type, COUNT(*) as count')
            ->groupBy('node_id', 'event_type')
            ->get();

        $nodeStats = [];

        foreach ($events as $event) {
            if (!isset($nodeStats[$event->node_id])) {
                $nodeStats[$event->node_id] = [
                    'node_id' => $event->node_id,
                    'entered' => 0,
                    'completed' => 0,
                ];
            }

            if ($event->event_type === 'node_entered') {
                $nodeStats[$event->node_id]['entered'] = $event->count;
            } else {
                $nodeStats[$event->node_id]['completed'] = $event->count;
            }
        }

        return array_values($nodeStats);
    }

    private function getPopularPaths($flow, $startDate, $endDate): array
    {
        // This is a simplified version - you might want to implement more sophisticated path tracking
        $conversations = Conversation::where('flow_id', $flow->id)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->limit(1000)
            ->get();

        $paths = [];

        foreach ($conversations as $conversation) {
            $events = AnalyticsEvent::where('conversation_id', $conversation->id)
                ->where('event_type', 'node_entered')
                ->orderBy('created_at')
                ->pluck('node_id')
                ->toArray();

            $pathKey = implode(' → ', array_slice($events, 0, 5)); // First 5 nodes
            $paths[$pathKey] = ($paths[$pathKey] ?? 0) + 1;
        }

        arsort($paths);

        return array_slice(array_map(fn($path, $count) => [
            'path' => $path,
            'count' => $count,
        ], array_keys($paths), $paths), 0, 10);
    }

    private function getDropOffPoints($flow, $startDate, $endDate): array
    {
        $abandonedConversations = Conversation::where('flow_id', $flow->id)
            ->where('status', 'abandoned')
            ->whereBetween('started_at', [$startDate, $endDate])
            ->pluck('id');

        if ($abandonedConversations->isEmpty()) {
            return [];
        }

        $lastNodes = AnalyticsEvent::whereIn('conversation_id', $abandonedConversations)
            ->where('event_type', 'node_entered')
            ->selectRaw('node_id, COUNT(*) as count')
            ->groupBy('node_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $totalAbandoned = $abandonedConversations->count();

        return $lastNodes->map(fn($node) => [
            'node_id' => $node->node_id,
            'drop_off_count' => $node->count,
            'drop_off_rate' => round(($node->count / $totalAbandoned) * 100, 2),
        ])->toArray();
    }

    private function generateCSV($data, $type): string
    {
        if ($type === 'conversations') {
            $csv = "ID,Chatbot,Phone,Status,Started,Ended,Duration,Messages\n";
            foreach ($data as $item) {
                $csv .= implode(',', [
                    $item->id,
                    $item->flow->name ?? 'N/A',
                    $item->whatsapp_user_phone,
                    $item->status,
                    $item->started_at->format('Y-m-d H:i:s'),
                    $item->ended_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    $item->getFormattedDuration() ?? 'N/A',
                    $item->message_count,
                ]) . "\n";
            }
        } else {
            $csv = "ID,Chatbot ID,Conversation ID,Event Type,Node ID,Created At\n";
            foreach ($data as $item) {
                $csv .= implode(',', [
                    $item->id,
                    $item->flow_id,
                    $item->conversation_id ?? 'N/A',
                    $item->event_type,
                    $item->node_id ?? 'N/A',
                    $item->created_at->format('Y-m-d H:i:s'),
                ]) . "\n";
            }
        }

        return $csv;
    }
}