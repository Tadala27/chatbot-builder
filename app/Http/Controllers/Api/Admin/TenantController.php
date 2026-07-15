<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenant\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(private TenantDatabaseManager $manager)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // $user = Auth::guard('system')->user();

        // Log::debug('Here is the user role and permissions',
        //     [
        //         'role' => $user->getRoleNames()->first(),
        //         'permissions' => $user->getPermissionsViaRoles()->pluck('name')->toArray(),
        //     ]);

        $tenants = Tenant::query()
            ->when($request->search, fn ($q) => $q->where('slug', 'like', "%{$request->search}%")
                  ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')) LIKE ?", ["%{$request->search}%"])
            )
            ->when($request->is_active, fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN))
            )
            ->when($request->subscription_tier, fn ($q) => $q->where('subscription_tier', $request->subscription_tier)
            )
            ->with('domains')
            ->withCount('domains')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'unique:tenants,slug', 'alpha_dash'],
            'subscription_tier' => ['required', Rule::in(['free', 'starter', 'professional', 'enterprise'])],
            'subscription_expires_at' => ['nullable', 'date', 'after:today'],
            'max_bots' => ['sometimes', 'integer', 'min:1'],
            'max_conversations_per_month' => ['sometimes', 'integer', 'min:100'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            // Primary domain
            'domain' => ['required', 'string', 'unique:domains,domain'],
            'domain_type' => ['sometimes', Rule::in(['custom', 'subdomain'])],
        ]);

        // Observer is enabled — it will call provision() automatically
        $tenant = Tenant::create([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'db_schema' => 'tenant_'.$validated['slug'],
            'deployment_mode' => 'shared',
            'subscription_tier' => $validated['subscription_tier'],
            'subscription_expires_at' => $validated['subscription_expires_at'] ?? null,
            'max_bots' => $validated['max_bots'] ?? 3,
            'max_conversations_per_month' => $validated['max_conversations_per_month'] ?? 1000,
            'is_active' => $validated['is_active'] ?? true,
            'settings' => $validated['settings'] ?? [],
        ]);

        $tenant->domains()->create([
            'domain' => $validated['domain'],
            'is_primary' => true,
        ]);

        return response()->json([
            'message' => 'Tenant created and provisioned successfully.',
            'tenant' => $tenant->load('domains'),
        ], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json(
            $tenant->load('domains')
                   ->append(['isSubscriptionActive'])
        );
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'subscription_tier' => ['sometimes', Rule::in(['free', 'starter', 'professional', 'enterprise'])],
            'subscription_expires_at' => ['nullable', 'date'],
            'max_bots' => ['sometimes', 'integer', 'min:1'],
            'max_conversations_per_month' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
        ]);

        $tenant->update($validated);

        return response()->json([
            'message' => 'Tenant updated successfully.',
            'tenant' => $tenant->fresh('domains'),
        ]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        // Soft delete — forceDelete triggers the observer to drop the DB
        $tenant->delete();

        return response()->json(['message' => 'Tenant deleted successfully.']);
    }

    public function activate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => true]);

        return response()->json(['message' => "Tenant [{$tenant->slug}] activated."]);
    }

    public function deactivate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => false]);

        return response()->json(['message' => "Tenant [{$tenant->slug}] deactivated."]);
    }

    public function impersonate(Tenant $tenant): JsonResponse
    {
        // Generate a short-lived token scoped to this tenant for the admin
        // to enter the tenant's workspace without needing credentials
        $token = \Illuminate\Support\Str::random(64);

        cache()->put(
            "impersonate:{$token}",
            ['tenant_id' => $tenant->id, 'admin_id' => auth()->id()],
            now()->addMinutes(5)
        );

        return response()->json([
            'message' => "Impersonation token issued for tenant [{$tenant->slug}].",
            'token' => $token,
            'expires_in' => 300,
            'tenant_domain' => $tenant->primaryDomain()?->domain,
        ]);
    }

    public function statistics(): JsonResponse
    {
        return response()->json([
            'total' => Tenant::count(),
            'active' => Tenant::where('is_active', true)->count(),
            'inactive' => Tenant::where('is_active', false)->count(),
            'by_tier' => Tenant::query()
                ->selectRaw('subscription_tier, COUNT(*) as count')
                ->groupBy('subscription_tier')
                ->pluck('count', 'subscription_tier'),
            'expiring_soon' => Tenant::query()
                ->where('subscription_expires_at', '<=', now()->addDays(30))
                ->where('subscription_expires_at', '>=', now())
                ->count(),
            'expired' => Tenant::query()
                ->where('subscription_expires_at', '<', now())
                ->count(),
        ]);
    }
}