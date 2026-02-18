<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleLogins
{
    protected $maxAttempts = 5;   // lock after 5 failures
    protected $decayMinutes = 15; // lock duration

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('post') || ! $request->routeIs('api.login')) {
            return $next($request);
        }

        $key = 'login:' . $request->ip() . ':' . $request->email;

        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
            ], 429)->header('Retry-After', $seconds);
        }

        $response = $next($request);

        // If login failed → increment
        if ($response->getStatusCode() === 401 && str_contains($response->getContent(), 'Invalid credentials')) {
            RateLimiter::hit($key, $this->decayMinutes * 60);
        } else {
            // Successful login → clear
            RateLimiter::clear($key);
        }

        return $response;
    }
}