<?php

declare(strict_types=1);

namespace Divoto\Cairn\Facades;

use Divoto\Cairn\Cairn as CairnManager;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\DeclineReason;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Reporting\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * The one facade Cairn ships.
 *
 * CLAUDE.md permits no facades inside `src/` except this one — everything else
 * injects its dependencies. This exists because `Cairn::event('signed_up')` in
 * an application controller is the ergonomics people expect from a Laravel
 * package, and denying it would not make anyone's code better.
 *
 * @method static void event(string $name, array<string, scalar|null> $properties = [], ?Request $request = null)
 * @method static void conversion(string $name, float|string|null $value = null, array<string, scalar|null> $properties = [], ?Request $request = null)
 * @method static void record(Entry $entry)
 * @method static void ignore(string ...$patterns)
 * @method static bool isIgnored(Request $request)
 * @method static int live()
 * @method static int digest()
 * @method static void touch(Request $request, ?string $page = null)
 * @method static DeclineReason|null decide(Request $request)
 * @method static Report report()
 * @method static SessionResolver sessions()
 *
 * @see CairnManager
 */
final class Cairn extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CairnManager::class;
    }
}
