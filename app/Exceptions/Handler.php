<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function render($request, Throwable $e)
{
    if ($request->is('api/*') || $request->is('tenant/*') || $request->expectsJson()) {

        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($e instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
            return response()->json(['message' => 'Access denied. You do not have the required permission.'], 403);
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => class_basename($e->getModel()).' not found.'], 404);
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $errorMessages = [];
            foreach ($e->errors() as $field => $messages) {
                $errorMessages[] = $field.': '.implode(', ', $messages);
            }
            return response()->json([
                'message' => 'Validation failed: '.implode('; ', $errorMessages),
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    return parent::render($request, $e);
}
    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
