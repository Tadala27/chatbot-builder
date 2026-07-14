<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\CustomVariable;
use App\Models\GlobalVariable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariableController extends Controller
{
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $query = CustomVariable::where('bot_id', $bot->id);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('slug', 'like', "%{$s}%")
                    ->orWhere('data_type', 'like', "%{$s}%");
            });
        }

        if ($request->filled('data_type')) {
            $query->where('data_type', $request->data_type);
        }

        if ($request->filled('is_sensitive')) {
            $query->where('is_sensitive', $request->boolean('is_sensitive'));
        }

        $query->orderBy(
            $request->get('sort', 'name'),
            $request->get('direction', 'asc')
        );

        // Every row in global_variables is, by construction, a system-level
        // global — no is_system filter needed, the whole table qualifies.
        $system = GlobalVariable::orderBy('name')
            ->get()
            ->map(fn ($v) => [
                'id' => null,
                'bot_id' => $bot->id,
                'name' => $v->name,
                'key' => $v->key,
                'data_type' => $v->data_type,
                'save_in' => $v->save_in,
                'is_system' => true,
                'use_in_js' => false,
                'is_sensitive' => false,
                'default_value' => null,
                'description' => $v->description,
            ]);

        if ($request->boolean('all')) {
            $custom = $query->get()->map(fn ($v) => $this->formatVariable($v));

            return response()->json([
                'data' => $system->concat($custom)->values(),
            ]);
        }

        $paginator = $query->paginate($request->get('per_page', 20));

        $custom = collect($paginator->items())->map(fn ($v) => $this->formatVariable($v));

        return response()->json([
            'data' => $system->concat($custom)->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'data_type' => 'required|in:string,number,boolean,json,date',
            'save_in' => 'required|in:conversation,user_property',
            'use_in_js' => 'boolean',
            'is_sensitive' => 'boolean',
            'default_value' => 'nullable|string',
            'description' => 'nullable|string|max:500',
        ]);

        // Any key already present in global_variables is reserved — every
        // row there is a system-level global, full stop.
        $isReservedKey = GlobalVariable::where('key', $validated['key'])->exists();

        if ($isReservedKey) {
            return response()->json([
                'message' => "Key '{$validated['key']}' is a system variable and cannot be redefined.",
            ], 422);
        }

        if (CustomVariable::where('bot_id', $bot->id)->where('key', $validated['key'])->exists()) {
            return response()->json([
                'message' => "Variable key '{$validated['key']}' already exists on this bot.",
            ], 422);
        }

        $variable = CustomVariable::create(array_merge($validated, ['bot_id' => $bot->id]));

        return response()->json([
            'message' => 'Variable created.',
            'variable' => $this->formatVariable($variable),
        ], 201);
    }

    public function update(Request $request, Bot $bot, CustomVariable $variable): JsonResponse
    {
        $this->authorizeVariable($bot, $variable);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'data_type' => 'sometimes|in:string,number,boolean,json,date',
            'save_in' => 'sometimes|in:conversation,user_property,global',
            'use_in_js' => 'sometimes|boolean',
            'is_sensitive' => 'sometimes|boolean',
            'default_value' => 'nullable|string',
            'description' => 'nullable|string|max:500',
        ]);

        $variable->update($validated);

        return response()->json([
            'message' => 'Variable updated.',
            'variable' => $this->formatVariable($variable->fresh()),
        ]);
    }

    public function destroy(Bot $bot, CustomVariable $variable): JsonResponse
    {
        $this->authorizeVariable($bot, $variable);

        $activeCount = $variable->conversationVariables()->count();

        $variable->delete();

        return response()->json([
            'message' => 'Variable deleted.',
            'active_count' => $activeCount,
        ]);
    }

    public function globalCatalog(Request $request): JsonResponse
    {
        $query = GlobalVariable::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('key', 'like', "%{$s}%"));
        }

        $variables = $query->orderBy('name')->get();

        return response()->json(['data' => $variables]);
    }

    private function formatVariable(CustomVariable $v): array
    {
        return [
            'id' => $v->id,
            'bot_id' => $v->bot_id,
            'name' => $v->name,
            'key' => $v->key,
            'placeholder' => $v->placeholder(),
            'data_type' => $v->data_type,
            'save_in' => $v->save_in,
            'use_in_js' => $v->use_in_js,
            'is_sensitive' => $v->is_sensitive,
            'is_system' => false,
            'default_value' => $v->default_value,
            'description' => $v->description,
            'created_at' => $v->created_at,
        ];
    }

    private function authorizeVariable(Bot $bot, CustomVariable $variable): void
    {
        if ($variable->bot_id !== $bot->id) {
            abort(404, 'Variable not found.');
        }
    }
}