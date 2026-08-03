<?php

declare(strict_types=1);

use Divoto\Cairn\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JSON API
|--------------------------------------------------------------------------
|
| Disabled by default. It reads everything the dashboard can, so it stays off
| until the deployer decides who may call it.
|
*/

Route::get('/report', ReportController::class)->name('api.report');
