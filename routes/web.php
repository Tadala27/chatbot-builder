<?php

// routes/web.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;

// Catch-all for Vue Router (must be last)
Route::get('/{any?}', [HomeController::class, 'show'])
    ->where('any', '^(?!api\/)(?!auth\/microsoft)[\/\w\.-]*');