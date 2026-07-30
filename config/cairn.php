<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cairn configuration — stub
|--------------------------------------------------------------------------
|
| Phase 0 ships this file so the publish tag, the config merge and the
| `cairn.enabled` kill switch all exist and are testable from the start.
| Phase 1 fills in the full option set (drivers, privacy, retention,
| recorders, dashboard, api, tenancy, pulse).
|
| CLAUDE.md: env() is permitted in this file and nowhere else in the package.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false, Cairn binds its no-op drivers, registers no middleware and
    | records nothing. The host application is otherwise unaffected.
    |
    */

    'enabled' => env('CAIRN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage location
    |--------------------------------------------------------------------------
    |
    | 'connection' selects the database connection Cairn reads and writes on.
    | Null uses the application default. 'table_prefix' namespaces every Cairn
    | table so the package can share a schema with the host application.
    |
    */

    'connection' => env('CAIRN_DB_CONNECTION'),

    'table_prefix' => 'cairn_',

];
