<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    // GET /api/team
    public function index(): JsonResponse
    {
        $tenant = Tenant::current();
        $users  = $tenant->users()->with('roles')->orderBy('name')->get();
        return response()->json(['users' => $users]);
    }

    // POST /api/team/invite
    public function invite(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role'  => 'required|in:tenant-admin,bot-builder,agent,viewer',
        ]);

        $password = Str::random(16);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($password),
            'password_reset_required' => true,
        ]);

        $user->assignRole($validated['role']);
        $tenant->users()->attach($user->id, ['is_primary' => true, 'joined_at' => now()]);

        activity()->causedBy(auth()->user())->performedOn($user)->log('User invited to tenant');

        // TODO: dispatch InviteUserMail with the temporary password

        return response()->json([
            'message'            => 'User invited successfully.',
            'user'               => $user->load('roles'),
            'temporary_password' => $password,
        ], 201);
    }

    // PUT /api/team/{user}/role
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $tenant = Tenant::current();

        if (!$tenant->users->contains($user->id)) {
            return response()->json(['message' => 'User not found in this tenant.'], 404);
        }

        $validated = $request->validate([
            'role' => 'required|in:tenant-admin,bot-builder,agent,viewer',
        ]);

        $user->syncRoles([$validated['role']]);

        activity()->causedBy(auth()->user())->performedOn($user)->log('User role updated');

        return response()->json(['message' => 'Role updated.', 'user' => $user->load('roles')]);
    }

    // DELETE /api/team/{user}
    public function remove(User $user): JsonResponse
    {
        $tenant = Tenant::current();

        if (!$tenant->users->contains($user->id)) {
            return response()->json(['message' => 'User not found in this tenant.'], 404);
        }

        $tenant->users()->detach($user->id);

        activity()->causedBy(auth()->user())->log("User removed from tenant: {$user->name}");

        return response()->json(['message' => 'User removed from tenant.']);
    }
}
