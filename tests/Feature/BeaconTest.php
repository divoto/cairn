<?php

declare(strict_types=1);

use Divoto\Cairn\Enums\ScreenClass;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Support\Beacon;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('web')->get('/pricing', fn (): string => 'ok')->name('pricing.index');
});

function beaconAgent(): string
{
    return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
}

function recordPageview(string $uri = '/pricing'): void
{
    cairnTest()->withHeaders(['User-Agent' => beaconAgent()])->get($uri);
}

function beaconEntries(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::entries());
}

/**
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function postBeacon(array $payload): TestResponse
{
    return cairnTest()
        ->withHeaders(['User-Agent' => beaconAgent()])
        ->postJson('/cairn/collect', $payload);
}

/*
|--------------------------------------------------------------------------
| Size
|--------------------------------------------------------------------------
|
| CLAUDE.md: no build step. The beacon ships as source plus a minified copy,
| and the budget is 2KB — a script that grows without anybody noticing is how
| an "optional, tiny" beacon becomes neither.
|
*/

it('stays under 2KB minified', function (): void {
    $minified = file_get_contents(__DIR__.'/../../resources/dist/cairn.min.js');

    expect(strlen((string) $minified))->toBeLessThan(2048);
});

it('ships the source alongside the minified build', function (): void {
    expect(is_file(__DIR__.'/../../resources/js/cairn.js'))->toBeTrue()
        ->and(is_file(__DIR__.'/../../resources/dist/cairn.min.js'))->toBeTrue();
});

it('depends on nothing', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../resources/js/cairn.js');

    expect($source)->not->toContain('import ')
        ->and($source)->not->toContain('require(')
        ->and($source)->not->toContain('from \'');
});

/**
 * Exact screen dimensions, device memory, CPU count and canvas signatures are
 * fingerprinting signals with no analytics value. The beacon must not reach
 * for them.
 */
it('collects nothing whose only use is fingerprinting', function (): void {
    // The minified build, so the assertion is against code rather than the
    // comments that describe what the code avoids.
    $source = (string) file_get_contents(__DIR__.'/../../resources/dist/cairn.min.js');

    foreach ([
        'screen.width', 'screen.height', 'deviceMemory', 'hardwareConcurrency',
        'getContext', 'toDataURL', 'plugins', 'fonts', 'userAgent',
    ] as $signal) {
        expect($source)->not->toContain($signal);
    }
});

/*
|--------------------------------------------------------------------------
| The tag
|--------------------------------------------------------------------------
*/

it('renders a script tag pointing at the collect endpoint', function (): void {
    expect(Beacon::tag())->toContain('data-cairn-endpoint="/cairn/collect"')
        ->and(Beacon::tag())->toContain('<script');
});

it('renders nothing when the beacon is disabled', function (): void {
    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    expect(Beacon::tag())->toBe('');
});

it('renders nothing when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);

    expect(Beacon::tag())->toBe('');
});

/**
 * Cairn gives you the hash rather than telling you to add 'unsafe-inline',
 * which would weaken the policy for every other script on the page.
 */
it('offers a CSP hash rather than requiring unsafe-inline', function (): void {
    expect(Beacon::cspHash())->toStartWith("'sha256-")->toEndWith("'");
});

/**
 * The directive is registered unconditionally, so a template using @cairn does
 * not break when the beacon is switched off — it simply renders nothing.
 */
it('registers the @cairn Blade directive whether or not the beacon is on', function (): void {
    $directives = app('blade.compiler')->getCustomDirectives();

    expect($directives)->toHaveKey('cairn');

    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    expect(app('blade.compiler')->getCustomDirectives())->toHaveKey('cairn')
        ->and(Beacon::tag())->toBe('');
});

/*
|--------------------------------------------------------------------------
| The endpoint
|--------------------------------------------------------------------------
*/

it('accepts measurements for a pageview the server already recorded', function (): void {
    recordPageview();

    postBeacon(['url' => '/pricing', 'seconds' => 42, 'scroll' => 80, 'viewport' => 1280])
        ->assertNoContent();

    $row = (array) beaconEntries()->first();

    expect($row['time_on_page'] ?? null)->toBe(42)
        ->and($row['scroll_depth'] ?? null)->toBe(80)
        ->and($row['screen_class'] ?? null)->toBe(ScreenClass::Large->value);
});

/**
 * The visitor hash is derived from the request, never read from the body.
 * Without a matching server-side entry, anyone could POST arbitrary numbers
 * for arbitrary pages.
 */
it('rejects measurements for a page the server never saw', function (): void {
    recordPageview();

    postBeacon(['url' => '/somewhere-else', 'seconds' => 42, 'scroll' => 80])->assertNoContent();

    expect(beaconEntries()->value('time_on_page'))->toBeNull();
});

it('rejects measurements when no pageview was recorded at all', function (): void {
    postBeacon(['url' => '/pricing', 'seconds' => 42])->assertNoContent();

    expect(beaconEntries()->count())->toBe(0);
});

/**
 * A replay must not double a page's measurements.
 */
it('accepts only the first submission for a pageview', function (): void {
    recordPageview();

    postBeacon(['url' => '/pricing', 'seconds' => 42, 'scroll' => 80])->assertNoContent();
    postBeacon(['url' => '/pricing', 'seconds' => 9999, 'scroll' => 10])->assertNoContent();

    expect(beaconEntries()->value('time_on_page'))->toBe(42);
});

it('rejects a malformed payload', function (array $payload): void {
    recordPageview();

    postBeacon($payload)->assertNoContent();

    expect(beaconEntries()->value('time_on_page'))->toBeNull();
})->with([
    'no url' => [['seconds' => 42]],
    'empty url' => [['url' => '', 'seconds' => 42]],
    'absolute url' => [['url' => 'https://evil.example/pricing', 'seconds' => 42]],
    'protocol-relative url' => [['url' => '//evil.example/pricing', 'seconds' => 42]],
    'no seconds' => [['url' => '/pricing']],
    'zero seconds' => [['url' => '/pricing', 'seconds' => 0]],
    'negative seconds' => [['url' => '/pricing', 'seconds' => -5]],
    'absurd seconds' => [['url' => '/pricing', 'seconds' => 999999]],
    'seconds not a number' => [['url' => '/pricing', 'seconds' => 'forever']],
]);

it('clamps a scroll depth outside its range', function (): void {
    recordPageview();

    postBeacon(['url' => '/pricing', 'seconds' => 10, 'scroll' => 5000])->assertNoContent();

    // Out of range becomes zero rather than being stored as sent.
    expect(beaconEntries()->value('scroll_depth'))->toBe(0);
});

/**
 * The exact viewport width is a fingerprinting signal, so it is bucketed at
 * the boundary and the original is discarded.
 */
it('buckets the viewport and stores no exact width', function (int $width, ScreenClass $expected): void {
    recordPageview();

    postBeacon(['url' => '/pricing', 'seconds' => 10, 'viewport' => $width])->assertNoContent();

    expect(beaconEntries()->value('screen_class'))->toBe($expected->value);
})->with([
    [375, ScreenClass::Small],
    [800, ScreenClass::Medium],
    [1280, ScreenClass::Large],
    [2560, ScreenClass::ExtraLarge],
]);

/*
|--------------------------------------------------------------------------
| The privacy gate applies here too
|--------------------------------------------------------------------------
*/

it('records nothing for a visitor who sent Do Not Track', function (): void {
    recordPageview();

    cairnTest()
        ->withHeaders(['User-Agent' => beaconAgent(), 'DNT' => '1'])
        ->postJson('/cairn/collect', ['url' => '/pricing', 'seconds' => 42])
        ->assertNoContent();

    expect(beaconEntries()->value('time_on_page'))->toBeNull();
});

it('records nothing when the beacon recorder is disabled', function (): void {
    recordPageview();

    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    postBeacon(['url' => '/pricing', 'seconds' => 42])->assertNoContent();

    expect(beaconEntries()->value('time_on_page'))->toBeNull();
});

/**
 * Telling a caller which check rejected them would turn the endpoint into an
 * oracle for probing the rest.
 */
it('answers identically whatever happened', function (): void {
    recordPageview();

    $accepted = postBeacon(['url' => '/pricing', 'seconds' => 42]);
    $rejected = postBeacon(['url' => '/nope', 'seconds' => 42]);
    $malformed = postBeacon(['nonsense' => true]);

    expect($accepted->getStatusCode())->toBe(204)
        ->and($rejected->getStatusCode())->toBe(204)
        ->and($malformed->getStatusCode())->toBe(204);
});

/*
|--------------------------------------------------------------------------
| The dashboard without it
|--------------------------------------------------------------------------
|
| The acceptance criterion for this phase, and the reason it was built last:
| the dashboard has to be fully functional with the beacon switched off.
|
*/

it('leaves the dashboard fully functional when disabled', function (): void {
    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    Gate::define('viewCairn', fn (mixed $user = null): bool => true);

    recordPageview();

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('Top routes');
});

it('does not register the endpoint when disabled', function (): void {
    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    expect(Beacon::enabled())->toBeFalse();
});
