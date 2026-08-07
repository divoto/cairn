<?php

declare(strict_types=1);

use Divoto\Cairn\Integrations\Integrations;
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
| The controller is whichever one `cairn.dashboard.driver` selects. Integrations
| answers that, because it is the only place allowed to know which optional
| packages are installed.
|
*/

Route::get('/', app(Integrations::class)->dashboardController())->name('dashboard');
