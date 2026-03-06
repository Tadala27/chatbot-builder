<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Key schema corrections from the original:
 *  - analytics_events has NO node_id column. Events store metadata in JSON.
 *  - Event types for dialog entry/exit are 'dialog_entered' / 'dialog_completed'
 *    (not node_entered/node_completed). The column in metadata->dialog_id carries
 *    the dialog reference where needed.
 *  - Flows belong to Bots, not directly to Tenants. Tenant scoping goes via
 *    conversations.tenant_id (conversations still carry tenant_id directly).
 *  - "top_flows" replaces "top_chatbots".
 */
class AnalyticsController extends Controller
{
    // =========================================================================
    // GET /api/analytics/overview
    // =========================================================================

    public function overview(Request $request): JsonResponse
    {
        $tenant    = Tenant::current();
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate   = $request->get('end_date', now()->toDateString());

        $base = Conversation::where('tenant_id', $tenant->id)
            ->whereBetween('started_at', [$startDate, $endDate]);

        return response()->json([
            'total_conversations'               => (clone $base)->count(),
            'active_conversations'              => (clone $base)->where('status', 'active')->count(),
            'completed_conversations'           => (clone $base)->where('status', 'completed')->count(),
            'abandoned_conversations'           => (clone $base)->where('status', 'abandoned')->count(),
            'handed_off_conversations'          => (clone $base)->where('status', 'handed_off')->count(),
            'completion_rate'                   => $this->completionRate($base),
            'average_duration_seconds'          => $this->averageDuration($base),
            'average_messages_per_conversation' => (clone $base)->avg('message_count') ?? 0,
            'conversations_by_day'              => $this->byDay($tenant->id, $startDate, $endDate),
            'conversations_by_status'           => $this->byStatus($tenant->id, $startDate, $endDate),
            'top_flows'                         => $this->topFlows($tenant->id, $startDate, $endDate),
        ]);
    }

    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/analytics
    // =========================================================================

    public function flow(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate   = $request->get('end_date', now()->toDateString());

        $base = Conversation::where('flow_id', $flow->id)
            ->whereBetween('started_at', [$startDate, $endDate]);

        return response()->json([
            'overview' => [
                'total_conversations'    => (clone $base)->count(),
                'completed_conversations'=> (clone $base)->where('status', 'completed')->count(),
                'abandoned_conversations'=> (clone $base)->where('status', 'abandoned')->count(),
                'completion_rate'        => $this->completionRate($base),
                'average_duration_seconds' => $this->averageDuration($base),
            ],
            'conversations_over_time' => $this->byDay(null, $startDate, $endDate, $flow->id),
            'dialog_analytics'        => $this->dialogAnalytics($flow->id, $startDate, $endDate),
            'popular_paths'           => $this->popularPaths($flow->id, $startDate, $endDate),
            'drop_off_points'         => $this->dropOffPoints($flow->id, $startDate, $endDate),
        ]);
    }

    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/analytics/paths
    // =========================================================================

    public function popularPathsEndpoint(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        return response()->json([
            'paths' => $this->popularPaths(
                $flow->id,
                $request->get('start_date', now()->subDays(30)->toDateString()),
                $request->get('end_date', now()->toDateString())
            ),
        ]);
    }

    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/analytics/drop-offs
    // =========================================================================

    public function dropOffPointsEndpoint(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        return response()->json([
            'drop_off_points' => $this->dropOffPoints(
                $flow->id,
                $request->get('start_date', now()->subDays(30)->toDateString()),
                $request->get('end_date', now()->toDateString())
            ),
        ]);
    }

    // =========================================================================
    // GET /api/analytics/export
    // =========================================================================

    public function export(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'format'     => 'required|in:csv,json',
            'type'       => 'required|in:conversations,events',
            'start_date' => 'required|date',
            'end_date'   => 'required|date',
            'flow_id'    => 'nullable|exists:flows,id',
        ]);

        if ($validated['type'] === 'conversations') {
            $query = Conversation::where('tenant_id', $tenant->id)
                ->whereBetween('started_at', [$validated['start_date'], $validated['end_date']]);

            if (!empty($validated['flow_id'])) {
                $query->where('flow_id', $validated['flow_id']);
            }

            $data = $query->with(['flow.bot', 'whatsappAccount'])->get();
        } else {
            $query = AnalyticsEvent::where('tenant_id', $tenant->id)
                ->whereBetween('created_at', [$validated['start_date'], $validated['end_date']]);

            if (!empty($validated['flow_id'])) {
                $query->where('flow_id', $validated['flow_id']);
            }

            $data = $query->get();
        }

        $filename = $validated['type'] . '_' . now()->format('Y-m-d');

        if ($validated['format'] === 'csv') {
            return response()->json([
                'data'     => base64_encode($this->generateCsv($data, $validated['type'])),
                'filename' => $filename . '.csv',
            ]);
        }

        return response()->json([
            'data'     => $data,
            'filename' => $filename . '.json',
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function completionRate($query): float
    {
        $total = (clone $query)->count();
        if ($total === 0) return 0.0;
        $completed = (clone $query)->where('status', 'completed')->count();
        return round(($completed / $total) * 100, 2);
    }

    private function averageDuration($query): ?float
    {
        $rows = (clone $query)->whereNotNull('ended_at')->get();
        if ($rows->isEmpty()) return null;
        return round($rows->avg(fn($c) => $c->getDuration()), 2);
    }

    private function byDay(?int $tenantId, string $startDate, string $endDate, ?int $flowId = null): array
    {
        $query = Conversation::whereBetween('started_at', [$startDate, $endDate]);

        if ($tenantId) $query->where('tenant_id', $tenantId);
        if ($flowId)   $query->where('flow_id', $flowId);

        return $query
            ->selectRaw('DATE(started_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function byStatus(int $tenantId, string $startDate, string $endDate): array
    {
        return Conversation::where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->toArray();
    }

    private function topFlows(int $tenantId, string $startDate, string $endDate, int $limit = 5): array
    {
        return Conversation::where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw('flow_id, COUNT(*) as conversation_count')
            ->with('flow:id,name,bot_id')
            ->groupBy('flow_id')
            ->orderByDesc('conversation_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Dialog-level analytics.
     *
     * analytics_events stores the dialog reference inside the JSON metadata
     * column as metadata->dialog_id. Event types are 'dialog_entered' and
     * 'dialog_completed' (no node_id column exists on the table).
     */
    private function dialogAnalytics(int $flowId, string $startDate, string $endDate): array
    {
        $events = AnalyticsEvent::where('flow_id', $flowId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('event_type', ['dialog_entered', 'dialog_completed'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dialog_id')) as dialog_id, event_type, COUNT(*) as count")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dialog_id')), event_type")
            ->get();

        $stats = [];
        foreach ($events as $event) {
            $did = $event->dialog_id;
            if (!isset($stats[$did])) {
                $stats[$did] = ['dialog_id' => $did, 'entered' => 0, 'completed' => 0];
            }
            if ($event->event_type === 'dialog_entered') {
                $stats[$did]['entered'] = (int) $event->count;
            } else {
                $stats[$did]['completed'] = (int) $event->count;
            }
        }

        // Add completion rate per dialog
        return array_values(array_map(function ($s) {
            $s['completion_rate'] = $s['entered'] > 0
                ? round(($s['completed'] / $s['entered']) * 100, 2)
                : 0.0;
            return $s;
        }, $stats));
    }

    /**
     * Most common paths through the flow (first 5 dialogs entered per conversation).
     * Tracks dialog sequence via metadata->dialog_id on 'dialog_entered' events.
     */
    private function popularPaths(int $flowId, string $startDate, string $endDate): array
    {
        $conversationIds = Conversation::where('flow_id', $flowId)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->limit(1000)
            ->pluck('id');

        if ($conversationIds->isEmpty()) return [];

        $paths = [];

        // Group events by conversation to reconstruct paths efficiently
        $allEvents = AnalyticsEvent::whereIn('conversation_id', $conversationIds)
            ->where('event_type', 'dialog_entered')
            ->orderBy('created_at')
            ->get(['conversation_id', 'metadata']);

        $byConversation = $allEvents->groupBy('conversation_id');

        foreach ($byConversation as $convId => $events) {
            $dialogIds = $events->map(fn($e) => $e->metadata['dialog_id'] ?? null)
                ->filter()
                ->take(5)
                ->values()
                ->toArray();

            if (empty($dialogIds)) continue;

            $key          = implode(' → ', $dialogIds);
            $paths[$key]  = ($paths[$key] ?? 0) + 1;
        }

        arsort($paths);

        return array_slice(
            array_map(fn($path, $count) => ['path' => $path, 'count' => $count], array_keys($paths), $paths),
            0, 10
        );
    }

    /**
     * Dialogs where abandoned conversations last triggered 'dialog_entered'.
     */
    private function dropOffPoints(int $flowId, string $startDate, string $endDate): array
    {
        $abandonedIds = Conversation::where('flow_id', $flowId)
            ->where('status', 'abandoned')
            ->whereBetween('started_at', [$startDate, $endDate])
            ->pluck('id');

        if ($abandonedIds->isEmpty()) return [];

        // Get the last 'dialog_entered' event per abandoned conversation
        $lastDialogs = AnalyticsEvent::whereIn('conversation_id', $abandonedIds)
            ->where('event_type', 'dialog_entered')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dialog_id')) as dialog_id, COUNT(*) as count")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dialog_id'))")
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $total = $abandonedIds->count();

        return $lastDialogs->map(fn($row) => [
            'dialog_id'      => $row->dialog_id,
            'drop_off_count' => (int) $row->count,
            'drop_off_rate'  => round(($row->count / $total) * 100, 2),
        ])->toArray();
    }

    private function generateCsv($data, string $type): string
    {
        if ($type === 'conversations') {
            $csv = "ID,Flow,Bot,Phone,Status,Started,Ended,Duration (s),Messages\n";
            foreach ($data as $item) {
                $csv .= implode(',', [
                    $item->id,
                    $item->flow->name         ?? 'N/A',
                    $item->flow->bot->name    ?? 'N/A',
                    $item->whatsapp_user_phone,
                    $item->status,
                    $item->started_at->format('Y-m-d H:i:s'),
                    $item->ended_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    $item->getDuration()      ?? 'N/A',
                    $item->message_count,
                ]) . "\n";
            }
        } else {
            $csv = "ID,Flow ID,Conversation ID,Event Type,Created At\n";
            foreach ($data as $item) {
                $csv .= implode(',', [
                    $item->id,
                    $item->flow_id,
                    $item->conversation_id ?? 'N/A',
                    $item->event_type,
                    $item->created_at->format('Y-m-d H:i:s'),
                ]) . "\n";
            }
        }

        return $csv;
    }

    private function authorizeFlow(Bot $bot, Flow $flow): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) abort(404, 'Bot not found.');
        if ($flow->bot_id !== $bot->id) abort(404, 'Flow not found.');
    }
}
