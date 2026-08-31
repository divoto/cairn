<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\BotDetector;
use Divoto\Cairn\Contracts\ConsentResolver;
use Divoto\Cairn\Enums\DeclineReason;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\PageViews;
use Illuminate\Http\Request;

function gate(): PrivacyGate
{
    return app(PrivacyGate::class);
}

/**
 * @param  array<string, string>  $headers
 */
function requestWith(array $headers = [], string $uri = '/pricing'): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create($uri, 'GET', server: $server);
}

it('records an ordinary request', function (): void {
    expect(gate()->decide(requestWith()))->toBeNull()
        ->and(gate()->allows(requestWith()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Privacy invariant: Do Not Track and Global Privacy Control
|--------------------------------------------------------------------------
|
| The project requires an explicit regression test that DNT: 1 and Sec-GPC: 1
| produce zero recorded entries. These are the visitor's expressed wishes, and
| they are evaluated before anything else so that no configuration below can
| record against them.
|
*/

it('refuses a request carrying Do Not Track', function (): void {
    expect(gate()->decide(requestWith(['DNT' => '1'])))->toBe(DeclineReason::DoNotTrack);
});

it('refuses a request carrying Global Privacy Control', function (): void {
    expect(gate()->decide(requestWith(['Sec-GPC' => '1'])))->toBe(DeclineReason::GlobalPrivacyControl);
});

it('treats only an explicit 1 as the signal', function (): void {
    expect(gate()->decide(requestWith(['DNT' => '0'])))->toBeNull()
        ->and(gate()->decide(requestWith(['Sec-GPC' => '0'])))->toBeNull();
});

it('can be configured to ignore the signals, and says so through the reason', function (): void {
    config()->set('cairn.privacy.respect_dnt', false);
    config()->set('cairn.privacy.respect_gpc', false);

    expect(gate()->decide(requestWith(['DNT' => '1', 'Sec-GPC' => '1'])))->toBeNull();
});

/**
 * Both signals are honoured independently: turning one off must not disable
 * the other.
 */
it('honours each signal independently', function (): void {
    config()->set('cairn.privacy.respect_dnt', false);

    expect(gate()->decide(requestWith(['DNT' => '1'])))->toBeNull()
        ->and(gate()->decide(requestWith(['Sec-GPC' => '1'])))->toBe(DeclineReason::GlobalPrivacyControl);
});

/**
 * The visitor's wishes are checked before sampling, bot detection and ignore
 * rules. If the order were reversed, a request could be excluded for a reason
 * that masked the fact that the visitor had asked not to be measured — and the
 * decline reason reported to cairn:doctor would be misleading.
 */
it('reports the visitor\'s own wishes ahead of any other reason', function (): void {
    config()->set('cairn.enabled', true);

    $reason = gate()->decide(
        requestWith(['DNT' => '1'], '/cairn/dashboard'),
        sampleRate: 0.0,
        recorder: PageViews::class,
    );

    expect($reason)->toBe(DeclineReason::DoNotTrack)
        ->and(DeclineReason::DoNotTrack->isVisitorChoice())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The master switch
|--------------------------------------------------------------------------
*/

it('refuses everything when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);

    expect(gate()->decide(requestWith()))->toBe(DeclineReason::Disabled);
});

/*
|--------------------------------------------------------------------------
| Consent
|--------------------------------------------------------------------------
*/

it('refuses when the configured consent resolver declines', function (): void {
    app()->bind(ConsentResolver::class, fn (): ConsentResolver => new class implements ConsentResolver
    {
        public function granted(Request $request): bool
        {
            return false;
        }
    });

    expect(gate()->decide(requestWith()))->toBe(DeclineReason::ConsentDenied);
});

/**
 * A resolver that throws is not evidence of consent. The safe direction is to
 * record nothing.
 */
it('treats a failing consent resolver as a refusal', function (): void {
    app()->bind(ConsentResolver::class, fn (): ConsentResolver => new class implements ConsentResolver
    {
        public function granted(Request $request): bool
        {
            throw new RuntimeException('the consent service is down');
        }
    });

    expect(gate()->decide(requestWith()))->toBe(DeclineReason::ConsentDenied);
});

/*
|--------------------------------------------------------------------------
| Prefetch
|--------------------------------------------------------------------------
|
| A speculative fetch is not a visit. Recording one inflates pageviews for
| pages nobody chose to open, and the inflation is worst on exactly the links a
| browser guesses are popular.
|
*/

it('refuses a speculative fetch', function (string $header, string $value): void {
    expect(gate()->decide(requestWith([$header => $value])))->toBe(DeclineReason::Prefetch);
})->with([
    'standard' => ['Sec-Purpose', 'prefetch'],
    'standard with prerender' => ['Sec-Purpose', 'prefetch;prerender'],
    'legacy chrome' => ['Purpose', 'prefetch'],
    'legacy x-purpose' => ['X-Purpose', 'preview'],
    'firefox' => ['X-Moz', 'prefetch'],
]);

it('is not fooled by header casing', function (): void {
    expect(gate()->decide(requestWith(['Sec-Purpose' => 'PREFETCH'])))->toBe(DeclineReason::Prefetch);
});

/*
|--------------------------------------------------------------------------
| Bots
|--------------------------------------------------------------------------
*/

it('refuses an automated client', function (): void {
    app()->bind(BotDetector::class, fn (): BotDetector => new class implements BotDetector
    {
        public function isBot(?string $userAgent): bool
        {
            return true;
        }
    });

    expect(gate()->decide(requestWith()))->toBe(DeclineReason::Bot);
});

/*
|--------------------------------------------------------------------------
| Ignore rules
|--------------------------------------------------------------------------
*/

it('ignores paths matching a configured pattern', function (): void {
    expect(gate()->decide(requestWith(uri: '/cairn/dashboard'), recorder: PageViews::class))
        ->toBe(DeclineReason::Ignored);
});

it('ignores Cairn\'s own dashboard and the usual tooling out of the box', function (string $path): void {
    expect(gate()->decide(requestWith(uri: $path), recorder: PageViews::class))
        ->toBe(DeclineReason::Ignored);
})->with([
    ['/cairn'],
    ['/telescope/requests'],
    ['/horizon/dashboard'],
    ['/pulse'],
    ['/up'],
    ['/_debugbar/assets/stylesheets'],
]);

it('records paths that match nothing', function (): void {
    expect(gate()->decide(requestWith(uri: '/pricing'), recorder: PageViews::class))->toBeNull();
});

it('applies no ignore rules when no recorder is named', function (): void {
    expect(gate()->decide(requestWith(uri: '/cairn/dashboard')))->toBeNull();
});

it('accepts a closure as an ignore rule', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.ignore', [
        fn (Request $request): bool => $request->path() === 'secret',
    ]);

    expect(gate()->decide(requestWith(uri: '/secret'), recorder: PageViews::class))
        ->toBe(DeclineReason::Ignored)
        ->and(gate()->decide(requestWith(uri: '/public'), recorder: PageViews::class))
        ->toBeNull();
});

it('skips an ignore rule that is neither a closure nor a string', function (): void {
    config()->set('cairn.recorders.'.PageViews::class.'.ignore', [
        42,
        '/pricing',
    ]);

    expect(gate()->decide(requestWith(uri: '/pricing'), recorder: PageViews::class))
        ->toBe(DeclineReason::Ignored)
        ->and(gate()->decide(requestWith(uri: '/checkout'), recorder: PageViews::class))
        ->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Sampling
|--------------------------------------------------------------------------
*/

it('records everything at a sample rate of one', function (): void {
    foreach (range(1, 50) as $ignored) {
        expect(gate()->decide(requestWith(), sampleRate: 1.0))->toBeNull();
    }
});

it('records nothing at a sample rate of zero', function (): void {
    foreach (range(1, 50) as $ignored) {
        expect(gate()->decide(requestWith(), sampleRate: 0.0))->toBe(DeclineReason::Sampled);
    }
});

it('records roughly the requested fraction in between', function (): void {
    $recorded = 0;

    foreach (range(1, 2000) as $ignored) {
        if (gate()->allows(requestWith(), sampleRate: 0.5)) {
            $recorded++;
        }
    }

    // Generous bounds: this asserts sampling happens at all and is not wildly
    // biased, not that a random number generator is fair.
    expect($recorded)->toBeGreaterThan(800)->toBeLessThan(1200);
});

/*
|--------------------------------------------------------------------------
| Decline reasons
|--------------------------------------------------------------------------
*/

it('explains every decline reason in plain language', function (): void {
    foreach (DeclineReason::cases() as $reason) {
        expect($reason->explain())->not->toBe('')
            ->and($reason->explain())->toEndWith('.');
    }
});

it('classifies which reasons reflect a visitor\'s wish', function (): void {
    expect(DeclineReason::DoNotTrack->isVisitorChoice())->toBeTrue()
        ->and(DeclineReason::GlobalPrivacyControl->isVisitorChoice())->toBeTrue()
        ->and(DeclineReason::OptedOut->isVisitorChoice())->toBeTrue()
        ->and(DeclineReason::ConsentDenied->isVisitorChoice())->toBeTrue()
        ->and(DeclineReason::Bot->isVisitorChoice())->toBeFalse()
        ->and(DeclineReason::Sampled->isVisitorChoice())->toBeFalse();
});
