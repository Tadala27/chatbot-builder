<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomFunction;
use App\Models\BuiltInFunction;
use App\Models\Tenant;
use App\Services\FunctionExecutor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CustomFunctionController extends Controller
{
    protected FunctionExecutor $executor;

    public function __construct(FunctionExecutor $executor)
    {
        $this->executor = $executor;
    }

    /**
     * List custom functions
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = CustomFunction::where('tenant_id', $tenant->id)
            ->with('creator');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by type
        if ($request->has('function_type')) {
            $query->where('function_type', $request->function_type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $functions = $query->paginate($request->get('per_page', 20));

        return response()->json($functions);
    }

    /**
     * Get single function
     */
    public function show(CustomFunction $function): JsonResponse
    {
        $tenant = Tenant::current();

        if ($function->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Function not found'], 404);
        }

        $function->load('creator');

        return response()->json(['function' => $function]);
    }

    /**
     * Create new function
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'function_type' => 'required|in:javascript,api_call,webhook,built_in',
            'code' => 'required|string',
            'parameters' => 'nullable|array',
            'return_type' => 'nullable|string|max:50',
            'is_async' => 'boolean',
            'timeout_seconds' => 'integer|min:1|max:300',
            'test_cases' => 'nullable|array',
        ]);

        // Auto-generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);

            $count = 1;
            while (CustomFunction::where('tenant_id', $tenant->id)
                ->where('slug', $validated['slug'])
                ->exists()
            ) {
                $validated['slug'] = Str::slug($validated['name']) . '-' . $count;
                $count++;
            }
        }

        // Validate syntax
        $syntaxErrors = $this->executor->validateSyntax(
            $validated['code'],
            $validated['function_type']
        );

        if (!empty($syntaxErrors)) {
            return response()->json([
                'message' => 'Function code has syntax errors',
                'errors' => ['code' => $syntaxErrors],
            ], 422);
        }

        $validated['tenant_id'] = $tenant->id;
        $validated['created_by'] = auth()->id();
        $validated['is_active'] = true;

        $function = CustomFunction::create($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($function)
            ->log('Custom function created');

        return response()->json([
            'message' => 'Function created successfully',
            'function' => $function,
        ], 201);
    }

    /**
     * Update function
     */
    public function update(Request $request, CustomFunction $function): JsonResponse
    {
        $tenant = Tenant::current();

        if ($function->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Function not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'code' => 'sometimes|string',
            'parameters' => 'nullable|array',
            'return_type' => 'nullable|string|max:50',
            'is_async' => 'sometimes|boolean',
            'timeout_seconds' => 'sometimes|integer|min:1|max:300',
            'test_cases' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        // Validate syntax if code is being updated
        if (isset($validated['code'])) {
            $syntaxErrors = $this->executor->validateSyntax(
                $validated['code'],
                $function->function_type
            );

            if (!empty($syntaxErrors)) {
                return response()->json([
                    'message' => 'Function code has syntax errors',
                    'errors' => ['code' => $syntaxErrors],
                ], 422);
            }
        }

        $function->update($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($function)
            ->log('Custom function updated');

        return response()->json([
            'message' => 'Function updated successfully',
            'function' => $function,
        ]);
    }

    /**
     * Delete function
     */
    public function destroy(CustomFunction $function): JsonResponse
    {
        $tenant = Tenant::current();

        if ($function->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Function not found'], 404);
        }

        $function->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Custom function deleted: ' . $function->name);

        return response()->json(['message' => 'Function deleted successfully']);
    }

    /**
     * Test function execution
     */
    public function test(Request $request, CustomFunction $function): JsonResponse
    {
        $tenant = Tenant::current();

        if ($function->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Function not found'], 404);
        }

        $validated = $request->validate([
            'parameters' => 'required|array',
        ]);

        $result = $this->executor->test($function, $validated['parameters']);

        return response()->json($result);
    }

    /**
     * Get built-in functions
     */
    public function builtInFunctions(Request $request): JsonResponse
    {
        $query = BuiltInFunction::query()->where('is_active', true);

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $functions = $query->orderBy('category')->orderBy('name')->get();

        // Group by category
        $grouped = $functions->groupBy('category');

        return response()->json([
            'functions' => $functions,
            'grouped' => $grouped,
            'categories' => $grouped->keys(),
        ]);
    }

    /**
     * Get function templates
     */
    public function templates(): JsonResponse
    {
        $templates = [
            'javascript' => [
                [
                    'name' => 'Simple Calculator',
                    'code' => "function calculate(a, b, operation) {\n  switch(operation) {\n    case 'add': return a + b;\n    case 'subtract': return a - b;\n    case 'multiply': return a * b;\n    case 'divide': return b !== 0 ? a / b : 'Error';\n    default: return 'Invalid operation';\n  }\n}\n\nreturn calculate(a, b, operation);",
                    'parameters' => ['a', 'b', 'operation'],
                ],
                [
                    'name' => 'String Formatter',
                    'code' => "function formatString(text, format) {\n  switch(format) {\n    case 'uppercase': return text.toUpperCase();\n    case 'lowercase': return text.toLowerCase();\n    case 'capitalize': return text.charAt(0).toUpperCase() + text.slice(1);\n    default: return text;\n  }\n}\n\nreturn formatString(text, format);",
                    'parameters' => ['text', 'format'],
                ],
            ],
            'api_call' => [
                [
                    'name' => 'GET Request',
                    'code' => json_encode([
                        'url' => 'https://api.example.com/endpoint',
                        'method' => 'GET',
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                    ], JSON_PRETTY_PRINT),
                ],
                [
                    'name' => 'POST Request',
                    'code' => json_encode([
                        'url' => 'https://api.example.com/endpoint',
                        'method' => 'POST',
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer {token}',
                        ],
                        'body' => [
                            'key' => 'value',
                        ],
                    ], JSON_PRETTY_PRINT),
                ],
            ],
            'webhook' => [
                [
                    'name' => 'Simple Webhook',
                    'code' => json_encode([
                        'url' => 'https://webhook.example.com/endpoint',
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                    ], JSON_PRETTY_PRINT),
                ],
            ],
        ];

        return response()->json($templates);
    }
}