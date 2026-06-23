<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class SystemLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = Activity::query()
            ->with('causer', 'subject')
            ->when($request->causer_id, fn ($q) => $q->where('causer_id', $request->causer_id)
            )
            ->when($request->subject_type, fn ($q) => $q->where('subject_type', $request->subject_type)
            )
            ->when($request->event, fn ($q) => $q->where('event', $request->event)
            )
            ->when($request->from, fn ($q) => $q->where('created_at', '>=', $request->from)
            )
            ->when($request->to, fn ($q) => $q->where('created_at', '<=', $request->to)
            )
            ->when($request->search, fn ($q) => $q->where('description', 'like', "%{$request->search}%")
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($logs);
    }
}
