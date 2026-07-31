<?php

declare(strict_types=1);

use Divoto\Cairn\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard routes
|--------------------------------------------------------------------------
|
| Registered under the configured path and middleware, behind the viewCairn
| gate. Every filter is a query parameter rather than a path segment, so the
| whole dashboard is one route and every view of it is a shareable URL.
|
*/

Route::get('/', DashboardController::class)->name('dashboard');
