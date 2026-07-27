<?php

// routes/web.php
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/{any?}', [HomeController::class, 'show'])
    ->where('any', '^(?!api\/)(?!tenant\/)(?!auth\/microsoft)[\/\w\.-]*');