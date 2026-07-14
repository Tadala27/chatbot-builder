<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now();
        $start = $now->copy()->startOfMonth();
        $prev = $now->copy()->subMonth()->startOfMonth();
        $prevEnd = $now->copy()->subMonth()->endOfMonth();

        // ── Bots ───────────────────────────────────────────────────────────
        $totalBots = Bot::count();
        $activeBots = Bot::where('is_active', true)->count();
        $publishedBots = Bot::whereNotNull('current_published_version_id')->count();
        $draftBots = Bot::whereNull('current_published_version_id')->count();

        // ── WhatsApp accounts ──────────────────────────────────────────────
        $whatsappAccounts = WhatsappAccount::where('is_active', true)->count();

        // ── Conversations (this month vs last month) ────────────────────────
        $convsThisMonth = Conversation::whereBetween('started_at', [$start, $now])->count();
        $convsPrevMonth = Conversation::whereBetween('started_at', [$prev, $prevEnd])->count();
        $convsChange = $this->percentChange($convsPrevMonth, $convsThisMonth);

        $activeConvs = Conversation::where('status', 'active')->count();
        $completedConvs = Conversation::where('status', 'completed')
            ->whereBetween('ended_at', [$start, $now])
            ->count();
        $handedOffConvs = Conversation::where('status', 'handed_off')
            ->whereBetween('started_at', [$start, $now])
            ->count();

        // ── Messages (this month) ──────────────────────────────────────────
        $msgsThisMonth = Message::whereBetween('sent_at', [$start, $now])->count();
        $msgsPrevMonth = Message::whereBetween('sent_at', [$prev, $prevEnd])->count();
        $msgsChange = $this->percentChange($msgsPrevMonth, $msgsThisMonth);

        $inboundMsgs = Message::where('direction', 'inbound')
            ->whereBetween('sent_at', [$start, $now])->count();
        $outboundMsgs = Message::where('direction', 'outbound')
            ->whereBetween('sent_at', [$start, $now])->count();

        $totalUsers = User::count();
        $activeUsers = User::where('is_active', true)->count();

        // ── Conversations by day (last 30 days — for chart) ────────────────
        $convsByDay = Conversation::select(
            DB::raw('DATE(started_at) as date'),
            DB::raw('COUNT(*) as total')
        )
            ->where('started_at', '>=', $now->copy()->subDays(29)->startOfDay())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(fn ($r) => (int) $r->total);

        $convChart = [];
        for ($i = 29; $i >= 0; --$i) {
            $day = $now->copy()->subDays($i)->format('Y-m-d');
            $convChart[] = ['date' => $day, 'total' => $convsByDay[$day] ?? 0];
        }

        // ── Messages by direction (last 7 days — for chart) ────────────────
        $msgsByDay = Message::select(
            DB::raw('DATE(sent_at) as date'),
            'direction',
            DB::raw('COUNT(*) as total')
        )
            ->where('sent_at', '>=', $now->copy()->subDays(6)->startOfDay())
            ->groupBy('date', 'direction')
            ->orderBy('date')
            ->get();

        $msgChart = [];
        for ($i = 6; $i >= 0; --$i) {
            $day = $now->copy()->subDays($i)->format('Y-m-d');
            $inbound = $msgsByDay->where('date', $day)->where('direction', 'inbound')->first();
            $outbound = $msgsByDay->where('date', $day)->where('direction', 'outbound')->first();
            $msgChart[] = [
                'date' => $day,
                'inbound' => $inbound ? (int) $inbound->total : 0,
                'outbound' => $outbound ? (int) $outbound->total : 0,
            ];
        }

        // ── Conversation status breakdown (for pie/donut) ──────────────────
        $statusBreakdown = Conversation::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $topBots = Bot::select('bots.id', 'bots.name')
            ->selectRaw('COUNT(conversations.id) as conversation_count')
            ->leftJoin('conversations', function ($join) use ($start, $now) {
                $join->on('conversations.bot_id', '=', 'bots.id')
                     ->whereBetween('conversations.started_at', [$start, $now]);
            })
            ->groupBy('bots.id', 'bots.name')
            ->orderByDesc('conversation_count')
            ->limit(5)
            ->get();

        // ── Recent conversations ────────────────────────────────────────────
        $recentConversations = Conversation::with(['bot:id,name'])
            ->select(
                'id', 'bot_id', 'bot_version_id',
                'whatsapp_user_name', 'whatsapp_user_phone',
                'status', 'message_count', 'started_at', 'last_message_at'
            )
            ->orderByDesc('last_message_at')
            ->limit(10)
            ->get();

        return response()->json([
            'stats' => [
                'bots' => [
                    'total' => $totalBots,
                    'active' => $activeBots,
                    'published' => $publishedBots,
                    'draft' => $draftBots,
                ],
                'whatsapp_accounts' => $whatsappAccounts,
                'conversations' => [
                    'this_month' => $convsThisMonth,
                    'prev_month' => $convsPrevMonth,
                    'change_pct' => $convsChange,
                    'active' => $activeConvs,
                    'completed' => $completedConvs,
                    'handed_off' => $handedOffConvs,
                ],
                'messages' => [
                    'this_month' => $msgsThisMonth,
                    'prev_month' => $msgsPrevMonth,
                    'change_pct' => $msgsChange,
                    'inbound' => $inboundMsgs,
                    'outbound' => $outboundMsgs,
                ],
                'users' => [
                    'total' => $totalUsers,
                    'active' => $activeUsers,
                ],
            ],
            'charts' => [
                'conversations_by_day' => $convChart,
                'messages_by_day' => $msgChart,
                'conversation_status' => $statusBreakdown,
            ],
            'top_bots' => $topBots,
            'recent_conversations' => $recentConversations,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function percentChange(int $prev, int $current): ?float
    {
        if ($prev === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $prev) / $prev) * 100, 1);
    }
}