<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    // GET /api/message-templates
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = MessageTemplate::where('tenant_id', $tenant->id);

        if ($request->filled('category'))      $query->where('category', $request->category);
        if ($request->filled('status'))        $query->where('status', $request->status);
        if ($request->filled('template_type')) $query->where('template_type', $request->template_type);
        if ($request->filled('language'))      $query->where('language', $request->language);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('name', 'like', "%{$s}%");
        }

        $templates = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return response()->json($templates);
    }

    // GET /api/message-templates/{template}
    public function show(MessageTemplate $template): JsonResponse
    {
        $this->authorizeTemplate($template);
        return response()->json(['template' => $template]);
    }

    // POST /api/message-templates
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'category'      => 'required|in:utility,marketing,authentication',
            'language'      => 'required|string|max:10',
            'template_type' => 'required|in:text,media,interactive,location',
            'content'       => 'required|array',
            'variables'     => 'nullable|array',
        ]);

        $template = MessageTemplate::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'status'    => 'draft',
        ]));

        return response()->json(['message' => 'Template created.', 'template' => $template], 201);
    }

    // PUT /api/message-templates/{template}
    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $this->authorizeTemplate($template);

        // Only draft templates can be edited; approved ones are immutable
        if ($template->status === 'approved') {
            return response()->json(['message' => 'Approved templates cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'content'   => 'sometimes|array',
            'variables' => 'nullable|array',
        ]);

        $template->update($validated);

        return response()->json(['message' => 'Template updated.', 'template' => $template]);
    }

    // DELETE /api/message-templates/{template}
    public function destroy(MessageTemplate $template): JsonResponse
    {
        $this->authorizeTemplate($template);

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    // -------------------------------------------------------------------------

    private function authorizeTemplate(MessageTemplate $template): void
    {
        if ($template->tenant_id !== Tenant::current()->id) {
            abort(404, 'Template not found.');
        }
    }
}
