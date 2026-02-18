<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    /**
     * Login user and create session with tenant context
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'       => 'required|email',
            'password'    => 'required',
            'remember_me' => 'sometimes|boolean',
        ]);

        $user = User::where('email', $request->email)->first();

        // ────── ACCOUNT LOCKED ──────
        if ($user && $user->isLocked()) {
            $lockedUntil  = Carbon::parse($user->locked_until);
            $minutesLeft  = (int) ceil(now()->diffInMinutes($lockedUntil));
            $humanMinutes = $minutesLeft === 1 ? '1 minute' : "$minutesLeft minutes";

            return response()->json([
                'message'      => "Your account is temporarily locked due to multiple failed login attempts. Please try again after {$humanMinutes}.",
                'locked'       => true,
                'minutes_left' => $minutesLeft,
                'locked_until' => $lockedUntil->toISOString(),
            ], 423);
        }

        // ────── WRONG CREDENTIALS ──────
        if (!$user || !Hash::check($request->password, $user->password)) {
            if ($user) {
                $user->incrementFailedLogin();
                $remaining = 5 - $user->failed_login_attempts;
                $warning = $remaining > 0 ? " You have {$remaining} attempt(s) left before lockout." : '';

                if ($user->failed_login_attempts >= 5) {
                    $user->lockAccount(15);
                    return response()->json([
                        'message' => 'Too many failed login attempts. Your account has been locked for 15 minutes.'
                    ], 423);
                }

                return response()->json([
                    'message' => 'Invalid email or password.' . $warning
                ], 401);
            }

            return response()->json([
                'message' => 'Invalid email or password.'
            ], 401);
        }

        // ────── CHECK TENANT DOMAIN ACCESS ──────
        $currentHost = $request->getHost();
        $tenants = $user->tenants()->get();
        $primaryTenant = $user->getPrimaryTenant();
        
        // If not super admin, verify they have access to this tenant domain
        if (!$user->is_super_admin) {
            $hasAccess = $tenants->contains(function ($tenant) use ($currentHost) {
                $tenantDomain = parse_url($tenant->domain, PHP_URL_HOST) ?? $tenant->domain;
                return $tenantDomain === $currentHost;
            });

            if (!$hasAccess && $tenants->isNotEmpty()) {
                return response()->json([
                    'message' => 'Access denied. Please use your tenant domain.',
                    'redirect_to' => $tenants->first()->domain,
                ], 403);
            }
        }

        // ────── SUCCESSFUL LOGIN ──────
        $user->resetFailedAttempts();
        $user->update(['last_login' => now()]);

        Auth::login($user, $request->boolean('remember_me'));
        $request->session()->regenerate();

        // Set the current tenant
        $currentTenant = $this->getCurrentTenantFromDomain($currentHost, $tenants, $user);
        if ($currentTenant) {
            // Use the makeCurrent() method on the tenant instance
            $currentTenant->makeCurrent();
            
            // Store in session for easy access
            session(['current_tenant_id' => $currentTenant->id]);
            session(['current_tenant_domain' => $currentTenant->domain]);
        }

        // Create token for API access (if needed)
        $expiresAt = $request->boolean('remember_me') ? now()->addDays(30) : null;
        $token = $user->createToken('auth-token-' . $currentHost, ['*'], $expiresAt)->plainTextToken;

        if ($user->password_reset_required) {
            Log::info('User login requires password reset', [
                'user_id' => $user->id,
                'tenant_id' => $currentTenant?->id
            ]);

            return response()->json([
                'message' => 'Password reset required',
                'password_reset_required' => true,
                'user_id' => $user->id,
                'token' => $token,
                'current_tenant' => $currentTenant,
            ]);
        }

        return response()->json([
            'message'          => 'Login successful',
            'accessToken'      => $token,
            'userData'         => $this->transformUser($user),
            'userAbilityRules' => $user->getAllPermissions()->pluck('name')->toArray(),
            'current_tenant'   => $currentTenant ? [
                'id' => $currentTenant->id,
                'name' => $currentTenant->name,
                'slug' => $currentTenant->slug,
                'domain' => $currentTenant->domain,
            ] : null,
            'tenants' => $tenants->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'domain' => $t->domain,
                'is_primary' => $primaryTenant?->id === $t->id,
            ]),
        ]);
    }

    /**
     * Get current tenant based on domain
     */
    private function getCurrentTenantFromDomain($host, $tenants, $user)
    {
        if ($user->is_super_admin) {
            // For super admin, find tenant by domain or return first
            $tenant = $tenants->first(function ($tenant) use ($host) {
                $tenantDomain = parse_url($tenant->domain, PHP_URL_HOST) ?? $tenant->domain;
                return $tenantDomain === $host;
            });
            
            return $tenant ?? $tenants->first();
        }

        // For regular users, find their tenant matching the domain
        return $tenants->first(function ($tenant) use ($host) {
            $tenantDomain = parse_url($tenant->domain, PHP_URL_HOST) ?? $tenant->domain;
            return $tenantDomain === $host;
        });
    }

    /**
     * Handle logout
     */
    public function logout(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            // Revoke current token
            $user->tokens()->delete();

            Log::info('User logged out', [
                'user_id' => $user->id,
                'tenant_id' => session('current_tenant_id')
            ]);
        }

        // Forget current tenant using the Tenant model
        if ($currentTenant = Tenant::current()) {
            $currentTenant->forgetCurrent();
        }
        
        // Clear session
        $request->session()->forget(['current_tenant_id', 'current_tenant_domain']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::logout();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Ensure tenant context is correct
        $currentHost = $request->getHost();
        $sessionTenantId = session('current_tenant_id');
        
        // Verify session tenant matches domain
        if ($sessionTenantId) {
            $sessionTenant = Tenant::find($sessionTenantId);
            if ($sessionTenant) {
                $tenantDomain = parse_url($sessionTenant->domain, PHP_URL_HOST) ?? $sessionTenant->domain;
                if ($tenantDomain !== $currentHost && !$user->is_super_admin) {
                    // Domain mismatch - force logout
                    return $this->forceLogout($request, 'Tenant domain mismatch');
                }
                
                // Make sure tenant is current
                $currentTenant = Tenant::current();
                if (!$currentTenant || $currentTenant->id !== $sessionTenant->id) {
                    $sessionTenant->makeCurrent();
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformUser($user)
        ]);
    }

    /**
     * Force logout due to security issue
     */
    private function forceLogout(Request $request, $reason)
    {
        Log::warning('Force logout', ['reason' => $reason, 'ip' => $request->ip()]);
        
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return response()->json([
            'message' => 'Session expired. Please login again.'
        ], 401);
    }

    /**
     * Get user profile with tenant info
     */
    public function profile(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $tenants = $user->tenants()->get();
        $currentTenant = Tenant::current();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->transformUser($user),
                'current_tenant' => $currentTenant ? [
                    'id' => $currentTenant->id,
                    'name' => $currentTenant->name,
                    'slug' => $currentTenant->slug,
                    'domain' => $currentTenant->domain,
                    'is_active' => $currentTenant->is_active,
                    'subscription_tier' => $currentTenant->subscription_tier,
                    'subscription_expires_at' => $currentTenant->subscription_expires_at?->toISOString(),
                    'max_chatbots' => $currentTenant->max_chatbots,
                    'max_conversations_per_month' => $currentTenant->max_conversations_per_month,
                    'settings' => $currentTenant->settings,
                ] : null,
                'tenants' => $tenants->map(fn($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'domain' => $t->domain,
                    'is_active' => $t->is_active,
                    'is_primary' => $user->getPrimaryTenant()?->id === $t->id,
                ]),
            ]
        ]);
    }

    /**
     * Update profile
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|unique:users,email,' . $user->id,
            'timezone' => 'sometimes|string|max:50',
            'locale'   => 'sometimes|string|max:10',
            'avatar'   => 'sometimes|url',
        ]);

        try {
            $user->update($request->only(['name', 'email', 'timezone', 'locale', 'avatar']));

            Log::info('User profile updated', [
                'user_id' => $user->id,
                'tenant_id' => Tenant::current()?->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $this->transformUser($user)
            ]);
        } catch (\Exception $e) {
            Log::error('Profile update failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile'
            ], 500);
        }
    }

    /**
     * Update password
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        if (Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['New password must be different from current password.'],
            ]);
        }

        try {
            $user->update(['password' => Hash::make($request->password)]);

            Log::info('User password changed', [
                'user_id' => $user->id,
                'tenant_id' => Tenant::current()?->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Password change failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to change password'
            ], 500);
        }
    }

    /**
     * Send password reset link
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['We could not find a user with that email address.'],
            ]);
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => __($status)
        ], 400);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update([
                    'password' => Hash::make($password),
                    'password_reset_required' => false,
                ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => __($status)
        ], 400);
    }

    /**
     * Force reset password (for users flagged to reset)
     */
    public function forceResetPassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'password_reset_required' => false,
        ]);

        Log::info('User password reset', [
            'user_id' => $user->id,
            'tenant_id' => Tenant::current()?->id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully'
        ]);
    }

    /**
     * Switch tenant context
     */
    public function switchTenant(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
        ]);

        $user = Auth::user();
        $tenantId = $request->tenant_id;

        // Check if user has access to tenant
        if (!$user->tenants->contains($tenantId) && !$user->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied to this tenant',
            ], 403);
        }

        $tenant = Tenant::find($tenantId);

        // Check if the domain matches the current host
        $currentHost = $request->getHost();
        $tenantDomain = parse_url($tenant->domain, PHP_URL_HOST) ?? $tenant->domain;
        
        if ($tenantDomain !== $currentHost) {
            // Return the domain to redirect to
            return response()->json([
                'success' => false,
                'message' => 'Please access from the correct domain',
                'redirect_to' => $tenant->domain,
            ], 302);
        }

        // Set as primary tenant
        $user->setPrimaryTenant($tenant);
        
        // Make tenant current
        $tenant->makeCurrent();
        session(['current_tenant_id' => $tenant->id]);
        session(['current_tenant_domain' => $tenant->domain]);

        Log::info('User switched tenant', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Switched to tenant successfully',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
            ],
        ]);
    }

    /**
     * Transform user for API response with permissions and roles
     */
    private function transformUser($user)
    {
        // Load relationships
        $user->load('roles.permissions', 'permissions', 'tenants');

        // Get all permissions (direct + via roles)
        $permissions = $user->getAllPermissions()->map(function ($permission) {
            return [
                'id' => $permission->id,
                'name' => $permission->name,
            ];
        })->values();

        // Get roles
        $roles = $user->roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
            ];
        })->values();

        $primaryTenant = $user->getPrimaryTenant();
        $currentTenant = Tenant::current();

        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'timezone' => $user->timezone,
            'locale' => $user->locale,
            'is_super_admin' => $user->is_super_admin,
            'is_active' => $user->is_active,
            'password_reset_required' => $user->password_reset_required,
            'last_login' => $user->last_login?->toIso8601String(),
            'failed_login_attempts' => $user->failed_login_attempts,
            'locked_until' => $user->locked_until?->toIso8601String(),
            'is_locked' => $user->isLocked(),
            'permissions' => $permissions,
            'roles' => $roles,
            'current_tenant' => $currentTenant ? [
                'id' => $currentTenant->id,
                'name' => $currentTenant->name,
                'slug' => $currentTenant->slug,
                'domain' => $currentTenant->domain,
            ] : null,
            'primary_tenant' => $primaryTenant ? [
                'id' => $primaryTenant->id,
                'name' => $primaryTenant->name,
                'slug' => $primaryTenant->slug,
                'domain' => $primaryTenant->domain,
            ] : null,
            'tenants' => $user->tenants->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'domain' => $t->domain,
                'is_primary' => $primaryTenant?->id === $t->id,
                'is_current' => $currentTenant?->id === $t->id,
            ]),
        ];
    }
}