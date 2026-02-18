<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = MessageTemplate::where('tenant_id', $tenant->id);

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $templates = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:utility,marketing,authentication',
            'language' => 'required|string|max:10',
            'template_type' => 'required|in:text,media,interactive,location',
            'content' => 'required|array',
            'variables' => 'nullable|array',
        ]);

        $validated['tenant_id'] = $tenant->id;
        $validated['status'] = 'draft';

        $template = MessageTemplate::create($validated);

        return response()->json([
            'message' => 'Template created successfully',
            'template' => $template,
        ], 201);
    }

    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        if ($template->tenant_id !== Tenant::current()->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'content' => 'sometimes|array',
            'variables' => 'nullable|array',
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Template updated successfully',
            'template' => $template,
        ]);
    }

    public function destroy(MessageTemplate $template): JsonResponse
    {
        if ($template->tenant_id !== Tenant::current()->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $template->delete();
        return response()->json(['message' => 'Template deleted successfully']);
    }
}