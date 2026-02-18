<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $tenant = Tenant::current();
        $users = $tenant->users()->with('roles')->orderBy('name')->get();
        return response()->json(['users' => $users]);
    }

    public function invite(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'name' => 'required|string|max:255',
            'role' => 'required|in:tenant-admin,bot-builder,agent,viewer',
        ]);

        $password = Str::random(16);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($password),
        ]);

        $user->assignRole($validated['role']);
        $tenant->users()->attach($user->id, ['is_primary' => false]);

        // TODO: Send invitation email with password

        activity()->causedBy(auth()->user())->performedOn($user)->log('User invited to tenant');

        return response()->json([
            'message' => 'User invited successfully',
            'user' => $user->load('roles'),
            'temporary_password' => $password,
        ], 201);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $tenant = Tenant::current();

        if (!$tenant->users->contains($user->id)) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'role' => 'required|in:tenant-admin,bot-builder,agent,viewer',
        ]);

        $user->syncRoles([$validated['role']]);

        activity()->causedBy(auth()->user())->performedOn($user)->log('User role updated');

        return response()->json([
            'message' => 'Role updated successfully',
            'user' => $user->load('roles'),
        ]);
    }

    public function remove(User $user): JsonResponse
    {
        $tenant = Tenant::current();

        if (!$tenant->users->contains($user->id)) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $tenant->users()->detach($user->id);

        activity()->causedBy(auth()->user())->log('User removed from tenant: ' . $user->name);

        return response()->json(['message' => 'User removed successfully']);
    }
}