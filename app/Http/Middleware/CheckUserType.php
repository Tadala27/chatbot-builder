<?php
// app/Http/Middleware/CheckUserType.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CheckUserType
{
    public function handle(Request $request, Closure $next, string $type)
    {
        $userType = Session::get('user_type');
        
        if (!$userType || $userType !== $type) {
            return response()->json([
                'message' => "Access denied. This route requires {$type} user privileges."
            ], 403);
        }
        
        return $next($request);
    }
}