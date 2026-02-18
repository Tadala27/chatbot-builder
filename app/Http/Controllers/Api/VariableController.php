<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\ChatbotVariable;
use App\Models\GlobalVariable;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VariableController extends Controller
{
    // Chatbot Variables
    public function indexChatbotVariables(Chatbot $chatbot): JsonResponse
    {

        $variables = $chatbot->variables()->orderBy('key')->get();

        return response()->json(['variables' => $variables]);
    }

    public function storeChatbotVariable(Request $request, Chatbot $chatbot): JsonResponse
    {

        $validated = $request->validate([
            'key' => 'required|string|max:255',
            'default_value' => 'nullable',
            'data_type' => 'required|in:string,number,boolean,json,date',
            'scope' => 'required|in:session,user,global',
            'description' => 'nullable|string',
        ]);

        $exists = $chatbot->variables()->where('key', $validated['key'])->exists();
        if ($exists) {
            return response()->json([
                'message' => 'Variable key already exists',
            ], 422);
        }

        $variable = $chatbot->variables()->create($validated);

        return response()->json([
            'message' => 'Variable created successfully',
            'variable' => $variable,
        ], 201);
    }

    public function updateChatbotVariable(Request $request, Chatbot $chatbot, ChatbotVariable $variable): JsonResponse
    {

        if ($variable->chatbot_id !== $chatbot->id) {
            return response()->json(['message' => 'Variable not found'], 404);
        }

        $validated = $request->validate([
            'default_value' => 'nullable',
            'data_type' => 'sometimes|in:string,number,boolean,json,date',
            'scope' => 'sometimes|in:session,user,global',
            'description' => 'nullable|string',
        ]);

        $variable->update($validated);

        return response()->json([
            'message' => 'Variable updated successfully',
            'variable' => $variable,
        ]);
    }

    public function destroyChatbotVariable(Chatbot $chatbot, ChatbotVariable $variable): JsonResponse
    {

        if ($variable->chatbot_id !== $chatbot->id) {
            return response()->json(['message' => 'Variable not found'], 404);
        }

        $variable->delete();

        return response()->json(['message' => 'Variable deleted successfully']);
    }

    // Global Variables
    public function indexGlobalVariables(): JsonResponse
    {
        $tenant = Tenant::current();
        $variables = GlobalVariable::where('tenant_id', $tenant->id)->orderBy('key')->get();

        return response()->json(['variables' => $variables]);
    }

    public function storeGlobalVariable(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'nullable',
            'data_type' => 'required|in:string,number,boolean,json,date',
            'is_encrypted' => 'boolean',
            'description' => 'nullable|string',
        ]);

        $exists = GlobalVariable::where('tenant_id', $tenant->id)
            ->where('key', $validated['key'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Variable key already exists'], 422);
        }

        $validated['tenant_id'] = $tenant->id;
        $variable = GlobalVariable::create($validated);

        return response()->json([
            'message' => 'Variable created successfully',
            'variable' => $variable,
        ], 201);
    }

    public function updateGlobalVariable(Request $request, GlobalVariable $variable): JsonResponse
    {
        $tenant = Tenant::current();

        if ($variable->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Variable not found'], 404);
        }

        $validated = $request->validate([
            'value' => 'nullable',
            'data_type' => 'sometimes|in:string,number,boolean,json,date',
            'is_encrypted' => 'sometimes|boolean',
            'description' => 'nullable|string',
        ]);

        $variable->update($validated);

        return response()->json([
            'message' => 'Variable updated successfully',
            'variable' => $variable,
        ]);
    }

    public function destroyGlobalVariable(GlobalVariable $variable): JsonResponse
    {
        $tenant = Tenant::current();

        if ($variable->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Variable not found'], 404);
        }

        $variable->delete();

        return response()->json(['message' => 'Variable deleted successfully']);
    }
}