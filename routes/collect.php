<?php

declare(strict_types=1);

use Divoto\Cairn\Http\Controllers\CollectController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Beacon endpoint
|--------------------------------------------------------------------------
|
| Registered separately from the dashboard, because it is public where the
| dashboard is gated. It accepts the optional beacon's measurements and does
| nothing else.
|
*/

Route::post('/collect', CollectController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('collect');
