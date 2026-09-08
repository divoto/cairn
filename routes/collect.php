<?php

declare(strict_types=1);

use Divoto\Cairn\Http\Controllers\CollectController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
| The beacon posts with sendBeacon(), which carries no CSRF token, so the
| check is lifted for this one route. The router lifts a middleware when the
| one in the group is the named class or a subclass of it. Laravel 13 made
| PreventRequestForgery the class the web group runs and ValidateCsrfToken a
| subclass of it, so naming only the subclass leaves the check in place there
| and every measurement is answered with a 419. Both are named; the one a
| given framework does not define is a string that matches nothing.
|
*/

Route::post('/collect', CollectController::class)
    ->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class])
    ->name('collect');
