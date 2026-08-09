<?php

declare(strict_types=1);

use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\Channel;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\OperatingSystem;
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('web')->get('/pricing', fn (): string => 'ok')->name('pricing.index');
    Route::middleware('web')->get('/orders/{order}/invoice', fn (): string => 'ok')->name('orders.invoice');
    Route::middleware('web')->get('/plain/{id}', fn (): string => 'ok');
});

function entries(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::entries());
}

/**
 * A browser-like request. Without a user agent the bot detector correctly
 * refuses to record, so every test here needs one.
 *
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function browse(string $uri, array $headers = []): TestResponse
{
    return cairnTest()->withHeaders(array_merge([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ], $headers))->get($uri);
}

it('records one entry for one request', function (): void {
    browse('/pricing')->assertOk();

    expect(entries()->count())->toBe(1);
});

it('records the route name rather than the URL', function (): void {
    browse('/pricing');

    expect(entries()->value('route'))->toBe('pricing.index');
});

/**
 * The thing a browser tag cannot do. Without this, one busy route fragments
 * into thousands of one-visit URLs and nothing aggregates.
 */
it('groups a parameterised route under one name', function (): void {
    browse('/orders/8814/invoice');
    browse('/orders/9921/invoice');
    browse('/orders/1/invoice');

    expect(entries()->distinct()->pluck('route')->all())->toBe(['orders.invoice'])
        ->and(entries()->count())->toBe(3);
});

it('collapses identifiers when a route has no name', function (): void {
    browse('/plain/551');

    expect(entries()->value('route'))->toBe('/plain/{id}');
});

/**
 * The escape hatch for applications where one named route serves the whole
 * catalogue: grouping by name would put every page on a single row, so the
 * path becomes the grouping instead — with identifiers still collapsed, since
 * fragmenting on order ids was never the thing anybody wanted.
 */
it('groups by path when configured to', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.group_by', 'path');

    browse('/pricing');
    browse('/orders/8814/invoice');
    browse('/orders/9921/invoice');

    expect(entries()->distinct()->orderBy('route')->pluck('route')->all())
        ->toBe(['/orders/{id}/invoice', '/pricing']);
});

it('groups by route pattern when configured to', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.group_by', 'uri');

    browse('/orders/8814/invoice');

    expect(entries()->value('route'))->toBe('/orders/{order}/invoice');
});

it('keeps grouping by route name when the setting is nonsense', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.group_by', 'sideways');

    browse('/pricing');

    expect(entries()->value('route'))->toBe('pricing.index');
});

it('records the response status and a server duration', function (): void {
    browse('/pricing');

    $row = (array) entries()->first();

    expect($row['status'] ?? null)->toBe(200)
        ->and($row['duration_ms'] ?? null)->toBeGreaterThanOrEqual(0);
});

/*
|--------------------------------------------------------------------------
| Privacy invariants, end to end
|--------------------------------------------------------------------------
*/

/**
 * The project requires an explicit regression test that no Set-Cookie header is
 * emitted in the default configuration.
 */
it('sets no cookie of its own', function (): void {
    $response = browse('/pricing');

    $cookies = $response->headers->getCookies();

    foreach ($cookies as $cookie) {
        expect($cookie->getName())->not->toStartWith('cairn');
    }
});

it('never touches the session', function (): void {
    browse('/pricing');

    expect(session()->all())->not->toHaveKey('cairn');
});

/**
 * The four privacy regressions, now against a real request rather than a unit.
 */
it('records nothing for a visitor sending Do Not Track', function (): void {
    browse('/pricing', ['DNT' => '1']);

    expect(entries()->count())->toBe(0);
});

it('records nothing for a visitor sending Global Privacy Control', function (): void {
    browse('/pricing', ['Sec-GPC' => '1']);

    expect(entries()->count())->toBe(0);
});

it('records nothing for a bot', function (): void {
    cairnTest()->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])
        ->get('/pricing');

    expect(entries()->count())->toBe(0);
});

it('records nothing for a prefetch', function (): void {
    browse('/pricing', ['Sec-Purpose' => 'prefetch']);

    expect(entries()->count())->toBe(0);
});

it('stores no address, user agent or referring URL', function (): void {
    browse('/pricing', ['Referer' => 'https://news.ycombinator.com/item?id=12345&secret=abc']);

    $row = (array) entries()->first();

    foreach ($row as $value) {
        if (! is_string($value)) {
            continue;
        }

        expect($value)->not->toContain('127.0.0.1')
            ->and($value)->not->toContain('Mozilla')
            // The host is kept; the path and query never are.
            ->and($value)->not->toContain('item?id')
            ->and($value)->not->toContain('secret');
    }

    expect($row['referrer_host'] ?? null)->toBe('news.ycombinator.com');
});

it('strips the query string except for campaign parameters', function (): void {
    browse('/pricing?utm_source=newsletter&token=super-secret&q=my+search');

    $url = asString(entries()->value('url'));

    expect($url)->toContain('utm_source=newsletter')
        ->and($url)->not->toContain('token')
        ->and($url)->not->toContain('super-secret')
        ->and($url)->not->toContain('my+search');
});

it('does not record a user id unless explicitly opted in', function (): void {
    browse('/pricing');

    expect(entries()->value('user_id'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Acquisition
|--------------------------------------------------------------------------
*/

it('classifies a visit with no referrer as direct', function (): void {
    browse('/pricing');

    expect(entries()->value('channel'))->toBe(Channel::Direct->value);
});

it('classifies a search referrer as organic', function (): void {
    browse('/pricing', ['Referer' => 'https://www.google.com/search?q=analytics']);

    expect(entries()->value('channel'))->toBe(Channel::Organic->value);
});

it('classifies a social referrer as social', function (): void {
    browse('/pricing', ['Referer' => 'https://mastodon.social/@someone/1']);

    expect(entries()->value('channel'))->toBe(Channel::Social->value);
});

/**
 * A visitor arriving from a Google ad has both a search referrer and a paid
 * medium. Counting that as organic would quietly overstate free traffic.
 */
it('lets a paid campaign override a search referrer', function (): void {
    browse('/pricing?utm_medium=cpc&utm_source=google', [
        'Referer' => 'https://www.google.com/search?q=analytics',
    ]);

    expect(entries()->value('channel'))->toBe(Channel::Paid->value);
});

it('records the campaign parameters', function (): void {
    browse('/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=spring');

    $row = (array) entries()->first();

    expect($row['utm_source'] ?? null)->toBe('newsletter')
        ->and($row['utm_medium'] ?? null)->toBe('email')
        ->and($row['utm_campaign'] ?? null)->toBe('spring')
        ->and($row['channel'] ?? null)->toBe(Channel::Email->value);
});

it('treats internal navigation as direct rather than a referral', function (): void {
    browse('/pricing', ['Referer' => 'http://localhost/some/other/page']);

    expect(entries()->value('channel'))->toBe(Channel::Direct->value);
});

/*
|--------------------------------------------------------------------------
| Device
|--------------------------------------------------------------------------
*/

it('classifies the device, browser and operating system', function (): void {
    browse('/pricing');

    $row = (array) entries()->first();

    expect($row['device_type'] ?? null)->toBe(DeviceType::Desktop->value)
        ->and($row['browser'] ?? null)->toBe(Browser::Chrome->value)
        ->and($row['os'] ?? null)->toBe(OperatingSystem::MacOS->value);
});

/*
|--------------------------------------------------------------------------
| Sessions, presence and uniques
|--------------------------------------------------------------------------
*/

it('opens exactly one session across several pages', function (): void {
    browse('/pricing');
    browse('/orders/1/invoice');
    browse('/plain/2');

    $sessions = app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::sessions());

    expect($sessions->count())->toBe(1)
        ->and(columnInt($sessions->value('page_count')))->toBe(3)
        ->and(boolval($sessions->value('is_bounce')))->toBeFalse();
});

/**
 * Regression: Laravel resolves terminable middleware from the container again
 * for terminate(), so handle() and terminate() ran on different instances and
 * the request timer was silently lost. The suite passed; real traffic recorded
 * a null duration on every pageview.
 */
it('measures server response time across handle and terminate', function (): void {
    browse('/pricing');

    expect(entries()->value('duration_ms'))->not->toBeNull();
});

/**
 * Regression: the session's acquisition columns were never written, so every
 * session reported a null channel however the visitor had arrived.
 */
it('records acquisition on the session, once, from the first page', function (): void {
    browse('/pricing?utm_source=newsletter&utm_medium=email', [
        'Referer' => 'https://mastodon.social/@someone/1',
    ]);

    // A later page must not overwrite how the visit started.
    browse('/plain/2');

    $session = (array) app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::sessions())
        ->first();

    expect($session['channel'] ?? null)->toBe(Channel::Email->value)
        ->and($session['utm_source'] ?? null)->toBe('newsletter')
        ->and($session['referrer_host'] ?? null)->toBe('mastodon.social')
        ->and($session['entry_url'] ?? null)->toContain('utm_source=newsletter');
});

it('attaches every entry in a visit to the same session', function (): void {
    browse('/pricing');
    browse('/plain/2');

    expect(entries()->distinct()->count('session'))->toBe(1);
});

it('counts the visitor as present', function (): void {
    browse('/pricing');

    expect(Cairn::live())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Sampling and ignoring
|--------------------------------------------------------------------------
*/

it('records nothing at a sample rate of zero', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.sample_rate', 0.0);

    browse('/pricing');

    expect(entries()->count())->toBe(0);
});

it('records everything at a sample rate of one', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.sample_rate', 1.0);

    foreach (range(1, 5) as $ignored) {
        browse('/pricing');
    }

    expect(entries()->count())->toBe(5);
});

it('records nothing when the recorder is disabled', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.enabled', false);

    browse('/pricing');

    expect(entries()->count())->toBe(0);
});

it('records nothing when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);

    browse('/pricing');

    expect(entries()->count())->toBe(0);
});

it('honours an ignore pattern for the path', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.ignore', ['pricing*']);

    browse('/pricing');

    expect(entries()->count())->toBe(0);
});

it('honours an ignore pattern for the route name', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.ignore', ['pricing.index']);

    browse('/pricing');

    expect(entries()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Events and conversions
|--------------------------------------------------------------------------
*/

it('records a named event with properties', function (): void {
    Route::middleware('web')->get('/signup', function (): string {
        Cairn::event('signed_up', ['plan' => 'pro']);

        return 'ok';
    });

    browse('/signup');

    $event = (array) entries()->where('type', 'event')->first();

    expect($event['name'] ?? null)->toBe('signed_up')
        ->and(json_decode(asString($event['properties'] ?? ''), true))->toBe(['plan' => 'pro']);
});

it('records a conversion with a value', function (): void {
    Route::middleware('web')->get('/buy', function (): string {
        Cairn::conversion('purchase', 49.99);

        return 'ok';
    });

    browse('/buy');

    $conversion = (array) entries()->where('type', 'conversion')->first();

    expect($conversion['name'] ?? null)->toBe('purchase')
        ->and(columnFloat($conversion['value'] ?? null))->toBe(49.99);
});

it('records no event for a visitor who sent Do Not Track', function (): void {
    Route::middleware('web')->get('/signup', function (): string {
        Cairn::event('signed_up');

        return 'ok';
    });

    browse('/signup', ['DNT' => '1']);

    expect(entries()->count())->toBe(0);
});

it('lets a controller opt a request out at runtime', function (): void {
    Route::middleware('web')->get('/maybe', function (): string {
        Cairn::ignore('maybe');

        return 'ok';
    });

    browse('/maybe');

    expect(entries()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Failure containment
|--------------------------------------------------------------------------
*/

/**
 * Project rule: never let a Cairn failure break the host application's request.
 */
it('serves the response normally when Cairn storage is gone', function (): void {
    Schema::connection(Tables::connection())->drop(Tables::entries());

    browse('/pricing')->assertOk()->assertSee('ok');
});
