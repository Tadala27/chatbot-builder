<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BuiltInFunction;
use App\Models\CustomFunction;
use App\Models\Tenant;
use App\Services\Bot\FunctionExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Custom functions are scoped to a Bot in the new schema.
 */
class CustomFunctionController extends Controller
{
    public function __construct(protected FunctionExecutor $executor) {}

    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        // Get custom functions with filters
        $customQuery = CustomFunction::where('bot_id', $bot->id);

        if ($request->filled('search')) {
            $s = $request->search;
            $customQuery->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('slug', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if ($request->filled('function_type') && $request->function_type !== 'built_in') {
            $customQuery->where('function_type', $request->function_type);
        }

        if ($request->filled('is_active')) {
            $customQuery->where('is_active', $request->boolean('is_active'));
        }

        // Get built-in functions (only if not filtering by custom function_type)
        $builtInFunctions = collect();
        if (!$request->filled('function_type') || $request->function_type === 'built_in') {
            $builtInQuery = BuiltInFunction::where('is_active', true);

            if ($request->filled('search')) {
                $s = $request->search;
                $builtInQuery->where(function ($q) use ($s) {
                    $q->where('name', 'like', "%{$s}%")
                        ->orWhere('description', 'like', "%{$s}%");
                });
            }

            $builtInFunctions = $builtInQuery->orderBy('name')->get()->map(function ($f) {
                return [
                    'id' => $f->id,
                    'name' => $f->name,
                    'slug' => $f->name,
                    'description' => $f->description,
                    'parameters' => $f->parameters,
                    'return_type' => $f->return_type,
                    'category' => $f->category,
                    'syntax' => $f->syntax,
                    'examples' => $f->examples,
                    'function_type' => 'built_in',
                    'is_system' => true,
                    'is_active' => true,
                    'timeout_seconds' => 30, // Default timeout for built-in
                ];
            });
        }

        // Get custom functions and format them
        $customFunctions = $customQuery->orderBy('name')->get()->map(function ($f) {
            return [
                'id' => $f->id,
                'name' => $f->name,
                'slug' => $f->slug,
                'description' => $f->description,
                'parameters' => $f->parameters,
                'return_type' => $f->return_type,
                'function_type' => $f->function_type,
                'is_system' => false,
                'is_active' => $f->is_active,
                'timeout_seconds' => $f->timeout_seconds ?? 30,
                'code' => $f->code,
            ];
        });

        // Combine all functions
        $allFunctions = $customFunctions->concat($builtInFunctions)->values();

        // Apply sorting to the combined collection
        $sortBy = $request->get('sort', 'name');
        $sortDirection = $request->get('direction', 'asc');

        $allFunctions = $allFunctions->sortBy(function ($item) use ($sortBy) {
            return $item[$sortBy] ?? '';
        });

        if ($sortDirection === 'desc') {
            $allFunctions = $allFunctions->reverse();
        }

        $allFunctions = $allFunctions->values();

        // Handle pagination
        if ($request->has('per_page') || $request->has('page')) {
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);

            // Slice the collection for the current page
            $items = $allFunctions->forPage($page, $perPage)->values();

            // Create paginator
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $allFunctions->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'data' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ]
            ]);
        }

        // Return all functions without pagination
        return response()->json([
            'data' => $allFunctions,
            'pagination' => null
        ]);
    }

    // GET /api/bots/{bot}/functions/{function}
    public function show(Bot $bot, CustomFunction $function): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeFunction($bot, $function);

        return response()->json(['function' => $function]);
    }

    // POST /api/bots/{bot}/functions
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'slug'            => 'nullable|string|max:255',
            'description'     => 'nullable|string',
            'function_type'   => 'required|in:javascript,webhook,built_in',
            'code'            => 'required|string',
            'parameters'      => 'nullable|array',
            'return_type'     => 'nullable|string|max:50',
            'timeout_seconds' => 'nullable|integer|min:1|max:300',
        ]);

        $validated['slug'] = $validated['slug'] ?? $this->uniqueSlug($bot->id, $validated['name']);

        $errors = $this->executor->validateSyntax($validated['code'], $validated['function_type']);
        if (!empty($errors)) {
            return response()->json(['message' => 'Syntax errors found.', 'errors' => ['code' => $errors]], 422);
        }

        $function = CustomFunction::create(array_merge($validated, [
            'bot_id'    => $bot->id,
            'is_active' => true,
        ]));

        activity()->causedBy(auth()->user())->performedOn($function)->log('Custom function created');

        return response()->json(['message' => 'Function created.', 'function' => $function], 201);
    }

    // PUT /api/bots/{bot}/functions/{function}
    public function update(Request $request, Bot $bot, CustomFunction $function): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeFunction($bot, $function);

        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'description'     => 'nullable|string',
            'code'            => 'sometimes|string',
            'parameters'      => 'nullable|array',
            'return_type'     => 'nullable|string|max:50',
            'timeout_seconds' => 'sometimes|integer|min:1|max:300',
            'is_active'       => 'sometimes|boolean',
        ]);

        if (isset($validated['code'])) {
            $errors = $this->executor->validateSyntax($validated['code'], $function->function_type);
            if (!empty($errors)) {
                return response()->json(['message' => 'Syntax errors found.', 'errors' => ['code' => $errors]], 422);
            }
        }

        $function->update($validated);

        activity()->causedBy(auth()->user())->performedOn($function)->log('Custom function updated');

        return response()->json(['message' => 'Function updated.', 'function' => $function]);
    }

    // DELETE /api/bots/{bot}/functions/{function}
    public function destroy(Bot $bot, CustomFunction $function): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeFunction($bot, $function);

        $function->delete();

        activity()->causedBy(auth()->user())->log("Custom function deleted: {$function->name}");

        return response()->json(['message' => 'Function deleted.']);
    }

    // POST /api/bots/{bot}/functions/{function}/test
    public function test(Request $request, Bot $bot, CustomFunction $function): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeFunction($bot, $function);

        $validated = $request->validate([
            'parameters' => 'required|array',
        ]);

        $result = $this->executor->test($function, $validated['parameters']);

        return response()->json($result);
    }

    // POST /api/bots/{bot}/functions/test-draft
    // Test an unsaved (draft) function without persisting it.
    // Must be registered BEFORE the {function} wildcard route.
    public function testDraft(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $data = $request->validate([
            'name'             => 'required|string',
            'slug'             => 'required|string',
            'function_type'    => 'required|in:javascript,webhook,built_in',
            'code'             => 'required|string',
            'parameters'       => 'nullable|array',
            'return_type'      => 'nullable|string',
            'timeout_seconds'  => 'nullable|integer|min:1|max:300',
            'test_parameters'  => 'nullable|array',
        ]);

        // Build a temporary (unsaved) CustomFunction instance for the executor
        $draft = new CustomFunction([
            'bot_id'          => $bot->id,
            'name'            => $data['name'],
            'slug'            => $data['slug'],
            'function_type'   => $data['function_type'],
            'code'            => $data['code'],
            'parameters'      => $data['parameters'] ?? [],
            'return_type'     => $data['return_type'] ?? 'string',
            'timeout_seconds' => $data['timeout_seconds'] ?? 30,
            'is_active'       => true,
        ]);

        $result = $this->executor->test($draft, $data['test_parameters'] ?? []);

        return response()->json($result);
    }

    // GET /api/built-in-functions
    public function builtInFunctions(Request $request): JsonResponse
    {
        $query = BuiltInFunction::where('is_active', true);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }

        $functions = $query->orderBy('category')->orderBy('name')->get();
        $grouped   = $functions->groupBy('category');

        return response()->json([
            'functions'  => $functions,
            'grouped'    => $grouped,
            'categories' => $grouped->keys(),
        ]);
    }

    // GET /api/functions/templates
    public function templates(): JsonResponse
    {
        return response()->json([
            'javascript' => [
                [
                    'name'       => 'Simple Calculator',
                    'parameters' => ['a', 'b', 'operation'],
                    'code'       => "function calculate(a, b, op) {\n  switch(op) {\n    case 'add':      return a + b;\n    case 'subtract': return a - b;\n    case 'multiply': return a * b;\n    case 'divide':   return b !== 0 ? a / b : 'Error';\n    default:         return 'Invalid operation';\n  }\n}\nreturn calculate(a, b, operation);",
                ],
                [
                    'name'       => 'String Formatter',
                    'parameters' => ['text', 'format'],
                    'code'       => "function fmt(text, format) {\n  switch(format) {\n    case 'uppercase':  return text.toUpperCase();\n    case 'lowercase':  return text.toLowerCase();\n    case 'capitalize': return text.charAt(0).toUpperCase() + text.slice(1);\n    default:           return text;\n  }\n}\nreturn fmt(text, format);",
                ],
            ],
            'webhook' => [
                [
                    'name' => 'Simple Webhook',
                    'code' => json_encode(['url' => 'https://webhook.example.com/endpoint', 'headers' => ['Content-Type' => 'application/json']], JSON_PRETTY_PRINT),
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) abort(404, 'Bot not found.');
    }

    private function authorizeFunction(Bot $bot, CustomFunction $function): void
    {
        if ($function->bot_id !== $bot->id) abort(404, 'Function not found.');
    }

    private function uniqueSlug(int $botId, string $name): string
    {
        $base  = Str::slug($name);
        $slug  = $base;
        $count = 1;
        while (CustomFunction::where('bot_id', $botId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $count++;
        }
        return $slug;
    }
}