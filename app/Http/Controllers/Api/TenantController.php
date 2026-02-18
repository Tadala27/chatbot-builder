<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    /**
     * List all tenants (Super Admin only)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->with(['users', 'flows']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Filter by subscription tier
        if ($request->has('subscription_tier')) {
            $query->where('subscription_tier', $request->subscription_tier);
        }

        // Sorting
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $tenants = $query->paginate($request->get('per_page', 20));

        return response()->json($tenants);
    }

    /**
     * Get single tenant
     */
    public function show(Tenant $tenant): JsonResponse
    {
       $tenant->load(['users', 'flows', 'whatsappAccounts']); 

        return response()->json([
            'tenant' => $tenant,
            'stats' => [
                'total_users' => $tenant->users()->count(),
                'total_flows' => $tenant->flows()->count(),
                'published_flows' => $tenant->flows()->published()->count(),
                'total_conversations' => $tenant->conversations()->count(),
                'conversations_this_month' => $tenant->conversations()
                    ->whereMonth('created_at', now()->month)
                    ->count(),
                'remaining_conversations' => $tenant->getConversationsThisMonth(),
                'usage_percentage' => $tenant->getUsagePercentage(),
            ],
        ]);
    }

    /**
     * Create new tenant
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:tenants,slug|max:255',
            'domain' => 'nullable|url|unique:tenants,domain',
            'subscription_tier' => 'required|in:free,starter,professional,enterprise',
            'subscription_expires_at' => 'nullable|date',
            'max_flows' => 'required|integer|min:1',
            'max_conversations_per_month' => 'required|integer|min:100',
            'settings' => 'nullable|array',
        ]);

        // Auto-generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);

            // Ensure uniqueness
            $count = 1;
            while (Tenant::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] = Str::slug($validated['name']) . '-' . $count;
                $count++;
            }
        }

        $tenant = Tenant::create($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($tenant)
            ->log('Tenant created');

        return response()->json([
            'message' => 'Tenant created successfully',
            'tenant' => $tenant,
        ], 201);
    }

    /**
     * Update tenant
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|unique:tenants,slug,' . $tenant->id . '|max:255',
            'domain' => 'sometimes|url|unique:tenants,domain,' . $tenant->id,
            'subscription_tier' => 'sometimes|in:free,starter,professional,enterprise',
            'subscription_expires_at' => 'nullable|date',
            'max_flows' => 'sometimes|integer|min:1',
            'max_conversations_per_month' => 'sometimes|integer|min:100',
            'settings' => 'sometimes|array',
        ]);

        $tenant->update($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($tenant)
            ->log('Tenant updated');

        return response()->json([
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Delete tenant
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
       
        if ($tenant->flows()->exists()) {
            return response()->json([
                'message' => 'Cannot delete tenant with active chatbots. Please delete all chatbots first.',
            ], 422);
        }

        $tenant->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Tenant deleted: ' . $tenant->name);

        return response()->json([
            'message' => 'Tenant deleted successfully',
        ]);
    }

    /**
     * Activate tenant
     */
    public function activate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => true]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($tenant)
            ->log('Tenant activated');

        return response()->json([
            'message' => 'Tenant activated successfully',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Deactivate tenant
     */
    public function deactivate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => false]);

        // Deactivate all chatbots
        $tenant->flows()->update(['is_active' => false]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($tenant)
            ->log('Tenant deactivated');

        return response()->json([
            'message' => 'Tenant deactivated successfully',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Get tenant statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::active()->count(),
            'inactive_tenants' => Tenant::where('is_active', false)->count(),
            'by_subscription_tier' => Tenant::selectRaw('subscription_tier, COUNT(*) as count')
                ->groupBy('subscription_tier')
                ->get(),
            'total_chatbots' => \App\Models\Flow::count(),
            'total_conversations' => \App\Models\Conversation::count(),
            'conversations_this_month' => \App\Models\Conversation::whereMonth('created_at', now()->month)
                ->count(),
        ];

        return response()->json($stats);
    }
}