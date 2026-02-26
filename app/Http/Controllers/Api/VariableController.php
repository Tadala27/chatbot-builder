<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomVariable;
use App\Models\Flow;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class VariableController extends Controller
{
    /**
     * Get all custom variables for a flow
     */
    public function index(Flow $flow): JsonResponse
    {
        $variables = CustomVariable::where('flow_id', $flow->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'variables' => $variables,
        ]);
    }

    /**
     * Store a new custom variable
     */
    public function store(Request $request, Flow $flow): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'save_in' => 'required|in:bot_variables,user_properties',
            'use_in_js' => 'boolean',
            'is_sensitive' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if variable name already exists in this flow
        $exists = CustomVariable::where('flow_id', $flow->id)
            ->where('name', $request->name)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Variable name already exists in this flow',
            ], 422);
        }

        $variable = CustomVariable::create([
            'flow_id' => $flow->id,
            'name' => $request->name,
            'save_in' => $request->save_in,
            'use_in_js' => $request->use_in_js ?? false,
            'is_sensitive' => $request->is_sensitive ?? false,
        ]);

        return response()->json([
            'message' => 'Variable created successfully',
            'variable' => $variable,
        ], 201);
    }

    /**
     * Update a custom variable
     */
    public function update(Request $request, Flow $flow, CustomVariable $variable): JsonResponse
    {
        // Verify variable belongs to this flow
        if ($variable->flow_id !== $flow->id) {
            return response()->json(['message' => 'Variable not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'save_in' => 'sometimes|in:bot_variables,user_properties',
            'use_in_js' => 'boolean',
            'is_sensitive' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If name is being changed, check for duplicates
        if ($request->has('name') && $request->name !== $variable->name) {
            $exists = CustomVariable::where('flow_id', $flow->id)
                ->where('name', $request->name)
                ->where('id', '!=', $variable->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Variable name already exists in this flow',
                ], 422);
            }
        }

        $variable->update($request->only([
            'name',
            'save_in',
            'use_in_js',
            'is_sensitive',
        ]));

        return response()->json([
            'message' => 'Variable updated successfully',
            'variable' => $variable,
        ]);
    }

    /**
     * Delete a custom variable
     */
    public function destroy(Flow $flow, CustomVariable $variable): JsonResponse
    {
        // Verify variable belongs to this flow
        if ($variable->flow_id !== $flow->id) {
            return response()->json(['message' => 'Variable not found'], 404);
        }

        $variable->delete();

        return response()->json([
            'message' => 'Variable deleted successfully',
        ]);
    }
}
