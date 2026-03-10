<?php

// routes/web.php
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaServeController;
use Illuminate\Support\Facades\Route;

Route::get('/media/bot/{storedFilename}', [MediaServeController::class, 'serve'])
    ->name('media.bot.serve')
    ->where('storedFilename', '[a-zA-Z0-9\-\.]+');

Route::get('/{any?}', [HomeController::class, 'show'])
    ->where('any', '^(?!api\/)(?!auth\/microsoft)[\/\w\.-]*');
