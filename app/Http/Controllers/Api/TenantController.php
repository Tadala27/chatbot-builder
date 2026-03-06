<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    // GET /api/tenants  (super-admin)
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->withCount(['whatsappAccounts', 'conversations']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('slug', 'like', "%{$s}%")
                ->orWhere('domain', 'like', "%{$s}%"));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('subscription_tier')) {
            $query->where('subscription_tier', $request->subscription_tier);
        }

        $query->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'));

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    // GET /api/tenants/{tenant}  (super-admin)
    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load(['users', 'whatsappAccounts']);

        $botIds  = $tenant->bots()->pluck('id');
        $flowIds = Flow::whereIn('bot_id', $botIds)->pluck('id');

        return response()->json([
            'tenant' => $tenant,
            'stats'  => [
                'total_users'              => $tenant->users()->count(),
                'total_bots'               => $botIds->count(),
                'total_flows'              => $flowIds->count(),
                'published_flows'          => Flow::whereIn('bot_id', $botIds)->where('status', 'published')->count(),
                'total_conversations'      => $tenant->conversations()->count(),
                'conversations_this_month' => $tenant->conversations()->whereMonth('started_at', now()->month)->count(),
                'usage_percentage'         => $tenant->getUsagePercentage(),
            ],
        ]);
    }

    // POST /api/tenants  (super-admin)
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                        => 'required|string|max:255',
            'slug'                        => 'nullable|string|max:100|unique:tenants,slug',
            'domain'                      => 'nullable|string|max:255|unique:tenants,domain',
            'subscription_tier'           => 'required|in:free,starter,professional,enterprise',
            'subscription_expires_at'     => 'nullable|date',
            'max_flows'                   => 'required|integer|min:1',
            'max_conversations_per_month' => 'required|integer|min:100',
            'settings'                    => 'nullable|array',
        ]);

        if (empty($validated['slug'])) {
            $base  = Str::slug($validated['name']);
            $slug  = $base;
            $count = 1;
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $base . '-' . $count++;
            }
            $validated['slug'] = $slug;
        }

        $tenant = Tenant::create($validated);

        activity()->causedBy(auth()->user())->performedOn($tenant)->log('Tenant created');

        return response()->json(['message' => 'Tenant created.', 'tenant' => $tenant], 201);
    }

    // PUT /api/tenants/{tenant}  (super-admin)
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name'                        => 'sometimes|string|max:255',
            'slug'                        => "sometimes|string|max:100|unique:tenants,slug,{$tenant->id}",
            'domain'                      => "nullable|string|max:255|unique:tenants,domain,{$tenant->id}",
            'subscription_tier'           => 'sometimes|in:free,starter,professional,enterprise',
            'subscription_expires_at'     => 'nullable|date',
            'max_flows'                   => 'sometimes|integer|min:1',
            'max_conversations_per_month' => 'sometimes|integer|min:100',
            'is_active'                   => 'sometimes|boolean',
            'settings'                    => 'sometimes|array',
        ]);

        $tenant->update($validated);

        activity()->causedBy(auth()->user())->performedOn($tenant)->log('Tenant updated');

        return response()->json(['message' => 'Tenant updated.', 'tenant' => $tenant]);
    }

    // DELETE /api/tenants/{tenant}  (super-admin)
    public function destroy(Tenant $tenant): JsonResponse
    {
        if ($tenant->bots()->exists()) {
            return response()->json([
                'message' => 'Delete all bots before removing this tenant.',
            ], 422);
        }

        $tenant->delete();

        activity()->causedBy(auth()->user())->log("Tenant deleted: {$tenant->name}");

        return response()->json(['message' => 'Tenant deleted.']);
    }

    // POST /api/tenants/{tenant}/activate  (super-admin)
    public function activate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => true]);
        activity()->causedBy(auth()->user())->performedOn($tenant)->log('Tenant activated');
        return response()->json(['message' => 'Tenant activated.', 'tenant' => $tenant]);
    }

    // POST /api/tenants/{tenant}/deactivate  (super-admin)
    public function deactivate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => false]);
        // Deactivate all bots (and thus flows) under this tenant
        $tenant->bots()->update(['is_active' => false]);
        activity()->causedBy(auth()->user())->performedOn($tenant)->log('Tenant deactivated');
        return response()->json(['message' => 'Tenant deactivated.', 'tenant' => $tenant]);
    }

    // GET /api/tenants/statistics  (super-admin)
    public function statistics(): JsonResponse
    {
        return response()->json([
            'total_tenants'    => Tenant::count(),
            'active_tenants'   => Tenant::where('is_active', true)->count(),
            'inactive_tenants' => Tenant::where('is_active', false)->count(),
            'by_tier'          => Tenant::selectRaw('subscription_tier, COUNT(*) as count')
                ->groupBy('subscription_tier')->get(),
            'total_conversations' => Conversation::count(),
            'conversations_this_month' => Conversation::whereMonth('started_at', now()->month)->count(),
        ]);
    }
}
