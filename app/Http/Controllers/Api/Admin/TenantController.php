<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenant\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(private TenantDatabaseManager $manager)
    {
    }

    public function index(Request $request): JsonResponse
    {
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
            'domain' => ['required', 'string', 'unique:domains,domain'],
            'domain_url' => ['required', 'url', 'max:255'],
        ]);

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
            'url' => $validated['domain_url'],
            'is_primary' => true,
        ]);

        $provisioned = true;
        $provisionError = null;

        try {
            $this->manager->provision($tenant);
            $tenant->update(['provisioned_at' => now()]);
        } catch (\Throwable $e) {
            $provisioned = false;
            $provisionError = $e->getMessage();
            Log::error("Tenant [{$tenant->slug}] created but provisioning failed: {$e->getMessage()}");
        }

        return response()->json([
            'message' => $provisioned
                ? 'Tenant created and provisioned successfully.'
                : 'Tenant created, but provisioning failed. Run provisioning manually.',
            'provisioned' => $provisioned,
            'provision_error' => $provisionError,
            'tenant' => $tenant->fresh('domains'),
        ], 201);
    }

    /**
     * Manually run provisioning for a tenant that failed or was created
     * without auto-provisioning (e.g. imported, or retried after failure).
     */
    public function provision(Tenant $tenant): JsonResponse
    {
        try {
            $this->manager->provision($tenant);
            $tenant->update(['provisioned_at' => now()]);

            return response()->json([
                'message' => "Tenant [{$tenant->slug}] provisioned successfully.",
                'tenant' => $tenant->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Manual provisioning failed for tenant [{$tenant->slug}]: {$e->getMessage()}");

            return response()->json([
                'message' => 'Provisioning failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List available tenant-guard roles for the "create user" form.
     */
    public function roles(Tenant $tenant): JsonResponse
    {
        if (!$tenant->provisioned_at) {
            return response()->json([
                'message' => 'Tenant has not been provisioned yet.',
            ], 422);
        }

        return response()->json([
            'roles' => $this->manager->getRolesForTenant($tenant),
        ]);
    }

    /**
     * Create a user directly in this tenant's database.
     */
    public function createUser(Request $request, Tenant $tenant): JsonResponse
    {
        if (!$tenant->provisioned_at) {
            return response()->json([
                'message' => 'Tenant has not been provisioned yet. Provision it first.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string'],
        ]);

        try {
            $user = $this->manager->createUserForTenant($tenant, $validated);

            return response()->json([
                'message' => "User created in tenant [{$tenant->slug}].",
                'user' => $user->only(['id', 'name', 'email']),
            ], 201);
        } catch (\Throwable $e) {
            Log::error("User creation failed for tenant [{$tenant->slug}]: {$e->getMessage()}");

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
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
            'domain' => ['sometimes', 'string'],
            'domain_url' => ['sometimes', 'url', 'max:255'],
        ]);

        $tenant->update($validated);

        if ($request->filled('domain') || $request->filled('domain_url')) {
            $primary = $tenant->domains()->where('is_primary', true)->first();

            if ($primary) {
                $primary->update(array_filter([
                    'domain' => $request->input('domain'),
                    'url' => $request->input('domain_url'),
                ], fn ($v) => $v !== null));
            } elseif ($request->filled('domain')) {
                $tenant->domains()->create([
                    'domain' => $request->input('domain'),
                    'url' => $request->input('domain_url'),
                    'is_primary' => true,
                ]);
            }
        }

        return response()->json([
            'message' => 'Tenant updated successfully.',
            'tenant' => $tenant->fresh('domains'),
        ]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
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