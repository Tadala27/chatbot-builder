<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());
        $base = Conversation::whereBetween('started_at', [$startDate, $endDate]);

        return response()->json([
            'total_conversations' => (clone $base)->count(),
            'active_conversations' => (clone $base)->where('status', 'active')->count(),
            'completed_conversations' => (clone $base)->where('status', 'completed')->count(),
            'abandoned_conversations' => (clone $base)->where('status', 'abandoned')->count(),
            'handed_off_conversations' => (clone $base)->where('status', 'handed_off')->count(),
            'completion_rate' => $this->completionRate($base),
            'average_duration_seconds' => $this->averageDuration($base),
            'average_messages_per_conversation' => (clone $base)->avg('message_count') ?? 0,
            'conversations_by_day' => $this->byDay($startDate, $endDate),
            'conversations_by_status' => $this->byStatus($startDate, $endDate),
            'top_bots' => $this->topBots($startDate, $endDate),
        ]);
    }

    public function bot(Request $request, Bot $bot): JsonResponse
    {
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        $base = Conversation::where('bot_id', $bot->id)
            ->whereBetween('started_at', [$startDate, $endDate]);

        return response()->json([
            'overview' => [
                'total_conversations' => (clone $base)->count(),
                'completed_conversations' => (clone $base)->where('status', 'completed')->count(),
                'abandoned_conversations' => (clone $base)->where('status', 'abandoned')->count(),
                'completion_rate' => $this->completionRate($base),
                'average_duration_seconds' => $this->averageDuration($base),
            ],
            'conversations_over_time' => $this->byDay($startDate, $endDate, $bot->id),
            'dialog_analytics' => $this->dialogAnalytics($bot->id, $startDate, $endDate),
            'popular_paths' => $this->popularPaths($bot->id, $startDate, $endDate),
            'drop_off_points' => $this->dropOffPoints($bot->id, $startDate, $endDate),
        ]);
    }

    public function popularPathsEndpoint(Request $request, Bot $bot): JsonResponse
    {
        return response()->json([
            'paths' => $this->popularPaths(
                $bot->id,
                $request->get('start_date', now()->subDays(30)->toDateString()),
                $request->get('end_date', now()->toDateString())
            ),
        ]);
    }

    public function dropOffPointsEndpoint(Request $request, Bot $bot): JsonResponse
    {
        return response()->json([
            'drop_off_points' => $this->dropOffPoints(
                $bot->id,
                $request->get('start_date', now()->subDays(30)->toDateString()),
                $request->get('end_date', now()->toDateString())
            ),
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|in:csv,json',
            'type' => 'required|in:conversations,events',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'bot_id' => 'nullable|exists:bots,id',
        ]);

        if ($validated['type'] === 'conversations') {
            $query = Conversation::whereBetween('started_at', [$validated['start_date'], $validated['end_date']]);

            if (!empty($validated['bot_id'])) {
                $query->where('bot_id', $validated['bot_id']);
            }

            $data = $query->with(['bot', 'whatsappAccount'])->get();
        } else {
            $query = AnalyticsEvent::whereBetween('created_at', [$validated['start_date'], $validated['end_date']]);

            if (!empty($validated['bot_id'])) {
                $query->where('bot_id', $validated['bot_id']);
            }

            $data = $query->get();
        }

        $filename = $validated['type'].'_'.now()->format('Y-m-d');

        if ($validated['format'] === 'csv') {
            return response()->json([
                'data' => base64_encode($this->generateCsv($data, $validated['type'])),
                'filename' => $filename.'.csv',
            ]);
        }

        return response()->json([
            'data' => $data,
            'filename' => $filename.'.json',
        ]);
    }

    private function completionRate($query): float
    {
        $total = (clone $query)->count();
        if ($total === 0) {
            return 0.0;
        }
        $completed = (clone $query)->where('status', 'completed')->count();

        return round(($completed / $total) * 100, 2);
    }

    private function averageDuration($query): ?float
    {
        $rows = (clone $query)->whereNotNull('ended_at')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->avg(fn ($c) => $c->getDuration()), 2);
    }

    private function byDay(string $startDate, string $endDate, ?string $botId = null): array
    {
        $query = Conversation::whereBetween('started_at', [$startDate, $endDate]);

        if ($botId) {
            $query->where('bot_id', $botId);
        }

        return $query
            ->selectRaw('DATE(started_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function byStatus(string $startDate, string $endDate): array
    {
        return Conversation::whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->toArray();
    }

    private function topBots(string $startDate, string $endDate, int $limit = 5): array
    {
        return Conversation::whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw('bot_id, COUNT(*) as conversation_count')
            ->with('bot:id,name')
            ->groupBy('bot_id')
            ->orderByDesc('conversation_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function dialogAnalytics(string $botId, string $startDate, string $endDate): array
    {
        $events = AnalyticsEvent::where('bot_id', $botId)
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

        return array_values(array_map(function ($s) {
            $s['completion_rate'] = $s['entered'] > 0
                ? round(($s['completed'] / $s['entered']) * 100, 2)
                : 0.0;

            return $s;
        }, $stats));
    }

    private function popularPaths(string $botId, string $startDate, string $endDate): array
    {
        $conversationIds = Conversation::where('bot_id', $botId)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->limit(1000)
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return [];
        }

        $paths = [];

        $allEvents = AnalyticsEvent::whereIn('conversation_id', $conversationIds)
            ->where('event_type', 'dialog_entered')
            ->orderBy('created_at')
            ->get(['conversation_id', 'metadata']);

        $byConversation = $allEvents->groupBy('conversation_id');

        foreach ($byConversation as $convId => $events) {
            $dialogIds = $events->map(fn ($e) => $e->metadata['dialog_id'] ?? null)
                ->filter()
                ->take(5)
                ->values()
                ->toArray();

            if (empty($dialogIds)) {
                continue;
            }

            $key = implode(' → ', $dialogIds);
            $paths[$key] = ($paths[$key] ?? 0) + 1;
        }

        arsort($paths);

        return array_slice(
            array_map(fn ($path, $count) => ['path' => $path, 'count' => $count], array_keys($paths), $paths),
            0,
            10
        );
    }

    private function dropOffPoints(string $botId, string $startDate, string $endDate): array
    {
        $abandonedIds = Conversation::where('bot_id', $botId)
            ->where('status', 'abandoned')
            ->whereBetween('started_at', [$startDate, $endDate])
            ->pluck('id');

        if ($abandonedIds->isEmpty()) {
            return [];
        }

        $lastDialogs = AnalyticsEvent::whereIn('conversation_id', $abandonedIds)
            ->where('event_type', 'dialog_entered')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dialog_id')) as dialog_id, COUNT(*) as count")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dialog_id'))")
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $total = $abandonedIds->count();

        return $lastDialogs->map(fn ($row) => [
            'dialog_id' => $row->dialog_id,
            'drop_off_count' => (int) $row->count,
            'drop_off_rate' => round(($row->count / $total) * 100, 2),
        ])->toArray();
    }

    private function generateCsv($data, string $type): string
    {
        if ($type === 'conversations') {
            $csv = "ID,Bot,Phone,Status,Started,Ended,Duration (s),Messages\n";
            foreach ($data as $item) {
                $csv .= implode(',', [
                    $item->id,
                    $item->bot->name ?? 'N/A',
                    $item->whatsapp_user_phone,
                    $item->status,
                    $item->started_at->format('Y-m-d H:i:s'),
                    $item->ended_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    $item->getDuration() ?? 'N/A',
                    $item->message_count,
                ])."\n";
            }
        } else {
            $csv = "ID,Bot ID,Conversation ID,Event Type,Created At\n";
            foreach ($data as $item) {
                $csv .= implode(',', [
                    $item->id,
                    $item->bot_id,
                    $item->conversation_id ?? 'N/A',
                    $item->event_type,
                    $item->created_at->format('Y-m-d H:i:s'),
                ])."\n";
            }
        }

        return $csv;
    }
}