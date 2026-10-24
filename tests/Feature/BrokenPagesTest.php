<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Http\Middleware\RecordNotFound;
use Divoto\Cairn\Support\ErrorPageKey;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\BrokenPages;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The gate defaults to local-only, which is what the suite runs as.
    Gate::define('viewCairn', fn (mixed $user = null): bool => true);

    Route::middleware('web')->get('/pricing', fn (): string => 'ok')->name('pricing.index');
    Route::middleware('web')->get('/missing-product', fn () => abort(404))->name('products.show');
});

function brokenEntries(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::entries());
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function follow(string $uri, array $headers = []): TestResponse
{
    return cairnTest()->withHeaders(array_merge([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ], $headers))->get($uri);
}

/**
 * Store one entry and roll its day up, at both grains the dashboard reads.
 */
function storeFailure(
    string $url,
    int $status = 404,
    ?string $referrer = null,
    EntryType $type = EntryType::NotFound,
    ?string $route = null,
    int $minute = 0,
): void {
    /** @var Collection<int, Entry> $collection */
    $collection = new Collection([new Entry(
        occurredAt: CarbonImmutable::now('UTC')->startOfDay()->addHours(9)->addMinutes($minute),
        type: $type,
        visitor: random_bytes(16),
        route: $route,
        url: $url,
        referrerHost: $referrer,
        status: $status,
    )]);

    app(Storage::class)->store($collection);
}

function rollUpBrokenPages(): void
{
    $today = CarbonImmutable::now('UTC');

    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Day);
    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Hour);
}

/*
|--------------------------------------------------------------------------
| Recording a request that matched no route
|--------------------------------------------------------------------------
*/

it('records a link to a URL with no route', function (): void {
    follow('/old-pricing', ['Referer' => 'https://blog.example.com/post'])->assertNotFound();

    $row = (array) brokenEntries()->first();

    expect(brokenEntries()->count())->toBe(1)
        ->and($row['type'] ?? null)->toBe('not_found')
        ->and($row['status'] ?? null)->toBe(404)
        ->and($row['url'] ?? null)->toBe('/old-pricing')
        ->and($row['referrer_host'] ?? null)->toBe('blog.example.com')
        ->and($row['route'] ?? null)->toBeNull();
});

/**
 * A scanner working through a list of paths is not traffic. Its requests
 * have to stay out of every number except the broken pages.
 */
it('counts an unmatched request as no pageview, session or visitor', function (): void {
    follow('/wp-login.php')->assertNotFound();

    $sessions = app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::sessions())
        ->count();

    expect($sessions)->toBe(0)
        ->and(app(Presence::class)->count())->toBe(0);

    rollUpBrokenPages();

    $total = Cairn::report()->lastDays(1)->metrics(Metric::Pageviews, Metric::Visitors, Metric::Errors)->total();

    expect($total->metric(Metric::Pageviews))->toBe(0.0)
        ->and($total->metric(Metric::Visitors))->toBe(0.0)
        ->and($total->metric(Metric::Errors))->toBe(1.0);
});

/**
 * A matched route belongs to the pageview middleware. Recording it here as
 * well would count the same failure twice.
 */
it('leaves a 404 from a matched route to the pageview middleware', function (): void {
    follow('/missing-product')->assertNotFound();

    $row = (array) brokenEntries()->first();

    expect(brokenEntries()->count())->toBe(1)
        ->and($row['type'] ?? null)->toBe('pageview')
        ->and($row['route'] ?? null)->toBe('products.show')
        ->and($row['status'] ?? null)->toBe(404);
});

it('records nothing for a page that worked', function (): void {
    follow('/pricing')->assertOk();

    expect(brokenEntries()->where('type', 'not_found')->count())->toBe(0);
});

it('does not record an unmatched request that is not someone following a link', function (string $method, array $headers): void {
    $test = cairnTest()->withHeaders(array_merge([
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ], $headers));

    $response = $method === 'POST' ? $test->post('/no-such-endpoint') : $test->get('/no-such-endpoint');

    $response->assertNotFound();

    expect(brokenEntries()->count())->toBe(0);
})->with([
    'a POST' => ['POST', []],
    'an API client asking for JSON' => ['GET', ['Accept' => 'application/json']],
]);

/**
 * The control for the test above: the same request, as a browser sends it,
 * is recorded. Without it, the test above would pass for any reason a
 * request went unrecorded.
 */
it('records the same unmatched request when a browser follows it', function (): void {
    follow('/no-such-endpoint', ['Accept' => 'text/html'])->assertNotFound();

    expect(brokenEntries()->count())->toBe(1);
});

it('honours the privacy signals for an unmatched request', function (string $header): void {
    follow('/old-pricing', [$header => '1'])->assertNotFound();

    expect(brokenEntries()->count())->toBe(0);
})->with(['DNT', 'Sec-GPC']);

it('records nothing for an unmatched request from a bot', function (): void {
    cairnTest()->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])
        ->get('/old-pricing')
        ->assertNotFound();

    expect(brokenEntries()->count())->toBe(0);
});

it('records nothing when switched off in config', function (): void {
    config()->set('cairn.enabled', false);

    follow('/old-pricing')->assertNotFound();

    expect(brokenEntries()->count())->toBe(0);
});

it('records nothing for an unmatched path the application ignores', function (): void {
    Cairn::ignore('old-*');

    follow('/old-pricing')->assertNotFound();

    expect(brokenEntries()->count())->toBe(0);
});

it('swallows a failure rather than passing it to the host application', function (): void {
    cairnStorageGone();

    $request = Request::create('/old-pricing', 'GET', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ]);

    expect(fn () => app(RecordNotFound::class)->terminate($request, new Response('', 404)))
        ->not->toThrow(Throwable::class);
});

/*
|--------------------------------------------------------------------------
| Scrubbing the URL
|--------------------------------------------------------------------------
|
| Nothing vouches for a path that matched no route. A password reset link
| with one character missing is a 404, and its token is still a credential.
*/

it('scrubs anything shaped like a secret out of an unmatched URL', function (string $requested, string $stored): void {
    follow($requested)->assertNotFound();

    expect(brokenEntries()->value('url'))->toBe($stored);
})->with([
    'a reset token' => ['/pasword/reset/9f86d081884c7d659a2feaa0c55ad015', '/pasword/reset/{id}'],
    'a mixed token' => ['/login/magic/aB3dE5fG7hJ9kL1m', '/login/magic/{id}'],
    'a numeric id' => ['/orders/88142/invoce', '/orders/{id}/invoce'],
    'a readable slug' => ['/blog/how-we-built-it', '/blog/how-we-built-it'],
    'a query string, UTM included' => ['/old-pricing?token=secret&utm_source=mail', '/old-pricing'],
]);

it('caps the length of an unmatched URL', function (): void {
    follow('/'.implode('/', array_fill(0, 100, 'segment')))->assertNotFound();

    expect(mb_strlen(asString(brokenEntries()->value('url'))))->toBe(255);
});

/*
|--------------------------------------------------------------------------
| Rollup
|--------------------------------------------------------------------------
*/

it('rolls failures up by status, URL and referring host', function (): void {
    storeFailure('/old-pricing', referrer: 'blog.example.com');
    storeFailure('/old-pricing', referrer: 'blog.example.com', minute: 1);
    storeFailure('/old-pricing', minute: 2);
    storeFailure('/checkout', status: 500, type: EntryType::Pageview, route: 'checkout.store', minute: 3);
    rollUpBrokenPages();

    $rows = Cairn::report()->lastDays(1)
        ->metrics(Metric::Errors)
        ->groupBy(Dimension::ErrorPage)
        ->orderByDesc(Metric::Errors)
        ->get();

    expect($rows)->toHaveCount(3)
        ->and(row($rows)->dimension('error_page'))->toBe(ErrorPageKey::for(404, '/old-pricing', 'blog.example.com'))
        ->and(row($rows)->metric(Metric::Errors))->toBe(2.0);
});

/**
 * A broken page is a failed request, so the one thing there is to count about
 * it is how many times it failed. Anything else asked of the dimension is
 * withheld rather than borrowed from the site-wide figure.
 */
it('measures only errors for a broken page', function (): void {
    expect(Metric::Errors->isMeasuredFor(Dimension::ErrorPage))->toBeTrue()
        ->and(Metric::Pageviews->isMeasuredFor(Dimension::ErrorPage))->toBeFalse();
});

it('counts the errors on each route', function (): void {
    storeFailure('/checkout', status: 500, type: EntryType::Pageview, route: 'checkout.store');
    storeFailure('/checkout', status: 200, type: EntryType::Pageview, route: 'checkout.store', minute: 1);
    rollUpBrokenPages();

    $row = Cairn::report()->lastDays(1)
        ->metrics(Metric::Pageviews, Metric::Errors)
        ->groupBy(Dimension::Route)
        ->get()
        ->first();

    expect($row?->metric(Metric::Pageviews))->toBe(2.0)
        ->and($row?->metric(Metric::Errors))->toBe(1.0);
});

it('rolls up the response status', function (): void {
    storeFailure('/pricing', status: 200, type: EntryType::Pageview, route: 'pricing.index');
    storeFailure('/old-pricing', minute: 1);
    rollUpBrokenPages();

    $statuses = Cairn::report()->lastDays(1)
        ->metrics(Metric::Pageviews, Metric::Errors)
        ->groupBy(Dimension::Status)
        ->orderByDesc(Metric::Pageviews)
        ->get()
        ->mapWithKeys(static fn (ReportRow $row): array => [
            (string) $row->dimension('status') => [$row->metric(Metric::Pageviews), $row->metric(Metric::Errors)],
        ])
        ->all();

    expect($statuses)->toBe(['200' => [1.0, 0.0], '404' => [0.0, 1.0]]);
});

/**
 * A scanner can request a thousand paths an hour. The rollup keeps the most
 * frequent combinations in each bucket, so it stays a fixed size whatever is
 * thrown at it.
 */
it('keeps only the most frequent broken pages in a bucket', function (): void {
    storeFailure('/linked-everywhere', referrer: 'news.example.com');
    storeFailure('/linked-everywhere', referrer: 'news.example.com');

    foreach (range(1, 120) as $probe) {
        storeFailure('/probe-'.$probe);
    }

    rollUpBrokenPages();

    $rows = Cairn::report()->lastDays(1)
        ->metrics(Metric::Errors)
        ->groupBy(Dimension::ErrorPage)
        ->interval(Period::Day)
        ->orderByDesc(Metric::Errors)
        ->get();

    expect($rows)->toHaveCount(100)
        ->and(row($rows)->dimension('error_page'))->toBe(ErrorPageKey::for(404, '/linked-everywhere', 'news.example.com'));
});

/*
|--------------------------------------------------------------------------
| The panel
|--------------------------------------------------------------------------
*/

it('renders the broken pages panel', function (): void {
    storeFailure('/old-pricing', referrer: 'blog.example.com');
    rollUpBrokenPages();

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Broken pages')
        ->assertSee('404 · /old-pricing · from blog.example.com');
});

it('ranks the broken pages by how often they failed', function (): void {
    storeFailure('/rare');
    storeFailure('/common', minute: 1);
    storeFailure('/common', minute: 2);
    rollUpBrokenPages();

    $rows = app(BrokenPages::class)->rows(new Filters(range: 'today'));

    expect(row($rows)->dimension('error_page'))->toBe(ErrorPageKey::for(404, '/common', null))
        ->and(row($rows)->metric(Metric::Errors))->toBe(2.0);
});

it('says nothing has failed when nothing has', function (): void {
    config()->set('cairn.dashboard.widgets', [BrokenPages::class]);

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Nothing recorded in this period.');
});

/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
*/

it('labels a broken page for a reader', function (string $key, string $label): void {
    expect(Dimension::ErrorPage->display($key))->toBe($label);
})->with([
    'with a referrer' => [ErrorPageKey::for(404, '/old', 'example.com'), '404 · /old · from example.com'],
    'without one' => [ErrorPageKey::for(500, '/checkout', null), '500 · /checkout'],
    'not a key at all' => ['/hand-written', '/hand-written'],
]);

it('rejects a key that is not a status, a URL and a host', function (string $key): void {
    expect(ErrorPageKey::parse($key))->toBeNull();
})->with([
    'not JSON' => ['nope'],
    'too short' => ['[404, "/old"]'],
    'a string status' => ['["404", "/old", null]'],
    'a numeric host' => ['[404, "/old", 7]'],
]);

it('labels a status with its reason phrase', function (): void {
    expect(Dimension::Status->display(404))->toBe('404 Not Found')
        ->and(Dimension::Status->display('503'))->toBe('503 Service Unavailable')
        ->and(Dimension::Status->display(299))->toBe('299');
});
