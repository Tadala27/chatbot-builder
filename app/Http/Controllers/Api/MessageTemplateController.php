<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MessageTemplate::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('template_type')) {
            $query->where('template_type', $request->template_type);
        }
        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('name', 'like', "%{$s}%");
        }

        $templates = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return response()->json($templates);
    }

    public function show(MessageTemplate $template): JsonResponse
    {
        return response()->json(['template' => $template]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:utility,marketing,authentication',
            'language' => 'required|string|max:10',
            'template_type' => 'required|in:text,media,interactive,location',
            'content' => 'required|array',
            'variables' => 'nullable|array',
        ]);


        $template = MessageTemplate::create(array_merge($validated, [
            'status' => 'draft',
        ]));

        return response()->json(['message' => 'Template created.', 'template' => $template], 201);
    }

    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        if ($template->status === 'approved') {
            return response()->json(['message' => 'Approved templates cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'content' => 'sometimes|array',
            'variables' => 'nullable|array',
        ]);

        $template->update($validated);

        return response()->json(['message' => 'Template updated.', 'template' => $template]);
    }

    public function destroy(MessageTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}