<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\Tenant\TenantStorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class AuthController extends Controller
{
    // ── POST /tenant/auth/login ────────────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $tenant = tenant();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.', 'code' => 'TENANT_NOT_FOUND'], 404);
        }

        if (!$tenant->is_active) {
            return response()->json(['message' => 'This organisation is inactive.', 'code' => 'TENANT_INACTIVE'], 403);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.', 'code' => 'INVALID_CREDENTIALS'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account is disabled.', 'code' => 'USER_INACTIVE'], 403);
        }

        Auth::guard('tenant')->login($user);

        Session::put([
            'tenant_slug' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'user_type' => 'tenant',
            'auth_guard' => 'tenant',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'roles' => $user->getRoleNames()->toArray(),
        ]);

        Session::regenerate();
        $user->update(['last_login' => now()]);

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
            'tenant' => $this->formatTenant($tenant),
        ]);
    }

    // ── GET /tenant/auth/me ────────────────────────────────────────────────
    public function me(): JsonResponse
    {
        $user = Auth::guard('tenant')->user();
        $tenant = tenant();

        if (!$user || !$tenant) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'type' => 'tenant',
            'user' => $this->formatUser($user),
            'tenant' => $this->formatTenant($tenant),
        ]);
    }

    // ── POST /tenant/auth/logout ───────────────────────────────────────────
    public function logout(): JsonResponse
    {
        Auth::guard('tenant')->logout();
        Session::flush();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ── Private formatters ─────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'type' => 'tenant',
            'roles' => $user->getRoleNames()->toArray(),
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'password_reset_required' => (bool) $user->password_reset_required,
        ];
    }

    private function formatTenant($tenant): array
    {
        $subscription = $tenant->subscription;
        $effectiveFeatures = $subscription ? $subscription->effectiveFeatures() : [];

        $logoUrl = null; // small — for header
        $logoUrlFull = null; // full  — for email

        try {
            $setting = CompanySetting::current();

            // Small logo → header icon
            if ($setting?->logo_path_small) {
                $logoUrl = TenantStorageManager::temporaryUrl(
                    $setting->logo_path_small,
                    minutes: 120,
                );
            }

            // Full logo → emails
            if ($setting?->logo_path) {
                $logoUrlFull = TenantStorageManager::temporaryUrl(
                    $setting->logo_path,
                    minutes: 120,
                );
            }
        } catch (\Throwable) {
        }

        // Then in the return array:
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'country' => $tenant->countryConfig?->country_name,
            'currency_code' => $tenant->countryConfig?->currency_code,
            'currency_symbol' => $tenant->countryConfig?->currency_symbol,
            'features' => $effectiveFeatures,
            'logo_url' => $logoUrl,       // small — used in header
            'logo_url_full' => $logoUrlFull,   // full  — used in emails
        ];
    }
}
