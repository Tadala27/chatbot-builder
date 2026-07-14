<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('roles')->orderBy('name')->get();

        return response()->json(['users' => $users]);
    }

    public function invite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:tenant-admin,bot-builder,agent,viewer',
        ]);

        $password = Str::random(16);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($password),
            'password_reset_required' => true,
        ]);

        $user->assignRole($validated['role']);

        activity()->causedBy(auth()->user())->performedOn($user)->log('User invited to tenant');

        return response()->json([
            'message' => 'User invited successfully.',
            'user' => $user->load('roles'),
            'temporary_password' => $password,
        ], 201);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|in:tenant-admin,bot-builder,agent,viewer',
        ]);

        $user->syncRoles([$validated['role']]);

        activity()->causedBy(auth()->user())->performedOn($user)->log('User role updated');

        return response()->json(['message' => 'Role updated.', 'user' => $user->load('roles')]);
    }

    public function remove(User $user): JsonResponse
    {
        $name = $user->name;
        $user->delete();

        activity()->causedBy(auth()->user())->log("User removed from tenant: {$name}");

        return response()->json(['message' => 'User removed from tenant.']);
    }
}