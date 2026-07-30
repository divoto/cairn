<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| These encode the non-negotiable rules from CLAUDE.md as executable
| assertions. A rule that lives only in prose is a rule that erodes; a rule
| that fails CI is a rule that holds.
|
| If one of these fails, the fix is the code, not the test.
|
| Rules covering namespaces that do not exist yet (Contracts, Integrations,
| Widgets) are added in the phase that introduces them.
|
*/

arch('src declares strict types everywhere')
    ->expect('Divoto\Cairn')
    ->toUseStrictTypes();

arch('src never reaches for debugging helpers')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('env() is confined to config/cairn.php')
    ->expect('env')
    ->not->toBeUsed();

arch('src uses no facade but Cairn itself')
    ->expect('Illuminate\Support\Facades')
    ->not->toBeUsed()
    ->ignoring('Divoto\Cairn\Facades');

arch('optional integrations stay behind the Integrations namespace')
    ->expect(['Livewire', 'Inertia', 'Laravel\Pulse'])
    ->not->toBeUsed()
    ->ignoring('Divoto\Cairn\Integrations');
