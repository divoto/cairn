<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\DeclineReason;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Maintenance\Doctor;
use Divoto\Cairn\Maintenance\Eraser;
use Divoto\Cairn\Maintenance\Finding;
use Divoto\Cairn\Privacy\OptOut;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function store(string $visitor, string $at = '2026-03-14 09:00:00', int|string|null $userId = null): void
{
    /** @var Collection<int, Entry> $collection */
    $collection = new Collection([new Entry(
        occurredAt: CarbonImmutable::parse($at, 'UTC'),
        type: EntryType::Pageview,
        visitor: $visitor,
        route: 'pricing.index',
        url: '/pricing',
        userId: $userId,
    )]);

    app(Storage::class)->store($collection);
}

function cairnTable(string $name): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::name($name));
}

/*
|--------------------------------------------------------------------------
| Opt-out
|--------------------------------------------------------------------------
|
| The one cookie Cairn will ever set, and it exists only to remember a "no".
| The visitor hash rotates every 24 hours, so there is nothing durable to hang
| a server-side suppression on — a refusal recorded against today's hash would
| silently expire at midnight.
|
*/

it('records a refusal in a cookie carrying no identifier', function (): void {
    $cookie = Cairn::optOut();

    expect($cookie->getName())->toBe(OptOut::COOKIE)
        ->and($cookie->getValue())->toBe('1')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time());
});

it('honours a refusal ahead of everything but DNT and GPC', function (): void {
    $request = Request::create('/pricing');
    $request->cookies->set(OptOut::COOKIE, '1');

    expect(app(PrivacyGate::class)->decide($request))->toBe(DeclineReason::OptedOut)
        ->and(DeclineReason::OptedOut->isVisitorChoice())->toBeTrue();
});

it('records nothing at all for a visitor who has opted out', function (): void {
    $request = Request::create('/pricing');
    $request->cookies->set(OptOut::COOKIE, '1');

    expect(app(PrivacyGate::class)->allows($request))->toBeFalse();
});

it('lets a visitor withdraw a refusal', function (): void {
    $cookie = Cairn::optIn();

    expect($cookie->getName())->toBe(OptOut::COOKIE)
        ->and($cookie->getExpiresTime())->toBeLessThan(time());
});

/**
 * A deployer's own consent banner has to be able to read and set it, so this
 * one cookie is deliberately not HttpOnly.
 */
it('leaves the opt-out cookie readable by the site\'s own scripts', function (): void {
    expect(Cairn::optOut()->isHttpOnly())->toBeFalse()
        ->and(Cairn::optOut()->getSameSite())->toBe('lax');
});

/*
|--------------------------------------------------------------------------
| Erasure
|--------------------------------------------------------------------------
*/

it('erases every row for a visitor', function (): void {
    $subject = random_bytes(16);
    $other = random_bytes(16);

    store($subject);
    store($other);

    app(Eraser::class)->forgetVisitor($subject);

    expect(cairnTable('entries')->count())->toBe(1)
        ->and(binaryValue(cairnTable('entries')->value('visitor')))->toBe($other);
});

/**
 * The half that gets forgotten. Deleting an entry without recomputing leaves
 * the person's activity still counted in every rollup — present in the totals,
 * merely no longer attributable. That is not erasure.
 */
it('recomputes the aggregates the erased rows contributed to', function (): void {
    $subject = random_bytes(16);

    store($subject);
    store(random_bytes(16));

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    $before = cairnTable('aggregates')
        ->where('period', Period::Day->value)
        ->where('type', Metric::Pageviews->value)
        ->where('aggregate', 'overall')
        ->sum('value');

    expect(columnFloat($before))->toBe(2.0);

    app(Eraser::class)->forgetVisitor($subject);

    $after = cairnTable('aggregates')
        ->where('period', Period::Day->value)
        ->where('type', Metric::Pageviews->value)
        ->where('aggregate', 'overall')
        ->sum('value');

    expect(columnFloat($after))->toBe(1.0);
});

it('accepts a visitor hash in hex, which is what the dashboard shows', function (): void {
    $subject = random_bytes(16);
    store($subject);

    app(Eraser::class)->forgetVisitor(bin2hex($subject));

    expect(cairnTable('entries')->count())->toBe(0);
});

it('erases a user when user attribution was enabled', function (): void {
    store(random_bytes(16), userId: 42);
    store(random_bytes(16), userId: 99);

    app(Eraser::class)->forgetUser(42);

    expect(cairnTable('entries')->count())->toBe(1)
        ->and(cairnTable('entries')->value('user_id'))->toBe(99);
});

it('finds nothing for a user when attribution was never enabled', function (): void {
    store(random_bytes(16));

    $removed = app(Eraser::class)->forgetUser(42);

    expect($removed['entries'])->toBe(0)
        ->and(cairnTable('entries')->count())->toBe(1);
});

it('erases through the console command', function (): void {
    $subject = random_bytes(16);
    store($subject);

    expect(Artisan::call('cairn:forget', ['subject' => bin2hex($subject), '--force' => true]))->toBe(0)
        ->and(cairnTable('entries')->count())->toBe(0);
});

/**
 * Implying a completeness Cairn cannot deliver would be worse than the
 * limitation. Somebody servicing an erasure request needs to know what was not
 * covered.
 */
it('says plainly that erasure reaches at most one day', function (): void {
    Artisan::call('cairn:forget', ['subject' => bin2hex(random_bytes(16)), '--force' => true]);

    // Captured once: Artisan::output() drains its buffer.
    $output = Artisan::output();

    expect($output)->toContain('rotates every 24 hours')
        ->and($output)->toContain('including Cairn');
});

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

it('exports everything held about a visitor', function (): void {
    $subject = random_bytes(16);
    store($subject);

    $export = app(Eraser::class)->exportVisitor($subject);

    $identity = $export['subject'];

    expect(is_array($identity) ? ($identity['hash'] ?? null) : null)->toBe(bin2hex($subject))
        ->and($export['entries'])->toHaveCount(1);
});

it('hex-encodes binary columns so the export is valid JSON', function (): void {
    $subject = random_bytes(16);
    store($subject);

    $json = json_encode(app(Eraser::class)->exportVisitor($subject));

    expect($json)->toBeString()
        ->and(json_decode((string) $json, true))->toBeArray();
});

it('explains the rotation limit in the export itself', function (): void {
    $export = app(Eraser::class)->exportVisitor(random_bytes(16));

    expect($export['note'] ?? '')->toContain('rotates every 24 hours');
});

it('exports through the console command', function (): void {
    store(random_bytes(16));

    expect(Artisan::call('cairn:export', ['subject' => bin2hex(random_bytes(16))]))->toBe(0)
        ->and(Artisan::output())->toContain('"subject"');
});

/*
|--------------------------------------------------------------------------
| Doctor
|--------------------------------------------------------------------------
|
| Every finding explains what a setting means and stops there. None of them
| asserts a legal conclusion, because compliance is a property of a deployment
| and its context, not of a configuration file.
|
*/

it('reports nothing on a default installation', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'array');

    expect(app(Doctor::class)->examine())->toBe([]);
});

it('reports each personal-data setting when it is on', function (string $key, string $expected): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'array');
    config()->set($key, true);

    $titles = array_map(
        static fn (Finding $f): string => $f->title,
        app(Doctor::class)->examine(),
    );

    expect(implode(' | ', $titles))->toContain($expected);
})->with([
    'durable identity' => ['cairn.privacy.durable_identity', 'Durable cookie identity is on'],
    'user tracking' => ['cairn.privacy.track_user_id', 'Authenticated user attribution is on'],
]);

it('reports city-level geo', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'array');
    config()->set('cairn.privacy.geo_precision', 'city');

    $titles = array_map(
        static fn (Finding $f): string => $f->title,
        app(Doctor::class)->examine(),
    );

    expect(implode(' | ', $titles))->toContain('city level');
});

it('reports a retention window longer than 26 months', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'array');
    config()->set('cairn.retention.entries', 1000);

    $titles = array_map(
        static fn (Finding $f): string => $f->title,
        app(Doctor::class)->examine(),
    );

    expect(implode(' | ', $titles))->toContain('1000 days');
});

it('reports a salt stored on disk', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.driver', 'file');

    $titles = array_map(
        static fn (Finding $f): string => $f->title,
        app(Doctor::class)->examine(),
    );

    expect(implode(' | ', $titles))->toContain('stored on disk');
});

it('reports Cairn being switched off', function (): void {
    config()->set('cairn.enabled', false);

    $findings = app(Doctor::class)->examine();
    $titles = array_map(static fn (Finding $f): string => $f->title, $findings);

    expect(implode(' | ', $titles))->toContain('Cairn is disabled');
});

/**
 * CLAUDE.md: never claim compliance, and never give legal advice.
 */
it('never asserts a legal conclusion', function (): void {
    config()->set('cairn.privacy.track_user_id', true);
    config()->set('cairn.privacy.durable_identity', true);
    config()->set('cairn.privacy.geo_precision', 'city');

    $prose = '';

    foreach (app(Doctor::class)->examine() as $finding) {
        $prose .= ' '.$finding->title.' '.$finding->implication;
    }

    $prose = strtolower($prose);

    foreach (['gdpr', 'compliant', 'compliance', 'lawful', 'legal', 'illegal', 'you must'] as $claim) {
        expect($prose)->not->toContain($claim);
    }
});

it('says so explicitly when it reports anything', function (): void {
    config()->set('cairn.privacy.track_user_id', true);

    Artisan::call('cairn:doctor');

    $output = Artisan::output();

    expect($output)->toContain('observations, not errors')
        ->toContain('cannot tell you whether');
});

it('runs on a default installation without findings', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'array');

    expect(Artisan::call('cairn:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to report');
});

it('registers all three data-subject commands', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('cairn:forget')
        ->toContain('cairn:export')
        ->toContain('cairn:doctor');
});
