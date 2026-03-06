<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BuiltInFunction;
use App\Models\CustomFunction;
use App\Models\Tenant;
use App\Services\FunctionExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Custom functions are scoped to a Bot in the new schema.
 */
class CustomFunctionController extends Controller
{
    public function __construct(protected FunctionExecutor $executor) {}

    // GET /api/bots/{bot}/functions
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $query = CustomFunction::where('bot_id', $bot->id);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('slug', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }

        if ($request->filled('function_type')) {
            $query->where('function_type', $request->function_type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'));

        return response()->json($query->paginate($request->get('per_page', 20)));
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
