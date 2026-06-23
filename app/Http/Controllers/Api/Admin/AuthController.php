<?php

// app/Http/Controllers/Api/Admin/AuthController.php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // ── POST /api/auth/login ───────────────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::guard('system')->attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        /** @var SystemUser $user */
        $user = Auth::guard('system')->user();

        if (!$user->is_active) {
            Auth::guard('system')->logout();

            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        Session::put([
            'auth_guard' => 'system',
            'user_type' => 'system',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'roles' => $user->getRoleNames()->toArray(),
        ]);

        Session::regenerate();
        $user->update(['last_login' => now()]);

        return response()->json([
            'type' => 'system',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => 'system',
                'roles' => $user->getRoleNames()->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            ],
            'tenant' => null,
        ]);
    }

    // ── GET /api/auth/me ───────────────────────────────────────────────────
    public function me(): JsonResponse
    {
        $user = Auth::guard('system')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'type' => 'system',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => 'system',
                'roles' => $user->getRoleNames()->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            ],
            'tenant' => null,
        ]);
    }

    // ── POST /api/auth/logout ──────────────────────────────────────────────
    public function logout(): JsonResponse
    {
        Auth::guard('system')->logout();
        Session::flush();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
