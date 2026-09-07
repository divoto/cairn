<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Concerns\HasAnalytics;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\TopContent;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * A host-application model, standing in for whatever a user would attach the
 * trait to.
 */
final class Article extends Model
{
    use HasAnalytics;

    protected $table = 'cairn_test_articles';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * A model that names itself, to exercise the analyticsLabel() hook.
 */
final class LabelledArticle extends Model
{
    use HasAnalytics;

    protected $table = 'cairn_test_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function analyticsLabel(): string
    {
        $title = $this->getAttribute('title');

        return is_string($title) ? $title : '';
    }
}

/**
 * A model whose table does not exist, to prove a broken subject type costs one
 * panel's labels rather than the page.
 */
final class Tableless extends Model
{
    protected $table = 'no_such_table';
}

/**
 * A model that reports no usable primary key.
 */
final class Keyless extends Model
{
    protected $table = 'cairn_test_articles';

    public $timestamps = false;

    public function getKey(): mixed
    {
        return null;
    }
}

// The `cairn_test_articles` table the models above live in comes from the fixture
// migration in tests/Fixtures/migrations, so it exists outside the transaction
// each test runs in.
beforeEach(function (): void {
    Route::middleware('web')->get('/articles/{article}', function (string $article): string {
        Article::query()->findOrFail($article)->trackView();

        return 'ok';
    })->name('articles.show');

    Route::middleware('web')->get('/labelled/{article}', function (string $article): string {
        LabelledArticle::query()->findOrFail($article)->trackView();

        return 'ok';
    })->name('labelled.show');
});

/**
 * A model's key as a string, for building URLs.
 */
function articleKey(Model $article): string
{
    $key = $article->getKey();

    return is_scalar($key) ? (string) $key : '';
}

/**
 * Roll today up, so the read helpers have aggregates to read.
 */
function rollupToday(): void
{
    $today = CarbonImmutable::now('UTC');

    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Day);
    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Hour);
}

/**
 * Write one rolled-up subject row directly, for the cases where the model
 * behind the key cannot be created through the normal path.
 */
function seedSubjectAggregate(string $key): void
{
    $today = CarbonImmutable::now('UTC')->startOfDay();

    $connection = app(DatabaseManager::class)->connection(Tables::connection());
    $encoded = (string) json_encode([Dimension::Subject->value => $key]);

    $connection->table(Tables::aggregates())->insert([
        'period' => Period::Hour->value,
        'bucket' => $today->addHours(9)->getTimestamp(),
        'aggregate' => Dimension::Subject->value,
        'key' => $encoded,
        'key_hash' => Binary::bind($connection, substr(hash('sha256', $encoded, true), 0, 16)),
        'type' => Metric::Pageviews->value,
        'value' => 1,
        'tenant_id' => '',
    ]);
}

function articleEntries(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::entries())
        ->whereNotNull('subject_type');
}

/**
 * @param  array<string, string>  $headers
 */
function readArticle(int|string $id, array $headers = []): void
{
    cairnTest()->withHeaders(array_merge([
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ], $headers))->get("/articles/{$id}");
}

/**
 * This is the capability that comes from living inside the application. An
 * external tag sees a URL; Cairn can be told the URL was a view of *this*
 * Article, and answer questions about models rather than about paths.
 */
it('records a polymorphic subject for a model', function (): void {
    $article = Article::query()->create(['title' => 'Cairn']);

    readArticle(articleKey($article));

    $row = (array) articleEntries()->first();

    expect($row['subject_type'] ?? null)->toBe(Article::class)
        ->and($row['subject_id'] ?? null)->toBe(articleKey($article))
        ->and($row['name'] ?? null)->toBe('viewed');
});

it('records a named event against a model', function (): void {
    $article = Article::query()->create(['title' => 'Cairn']);

    Route::middleware('web')->get('/share/{article}', function (string $article): string {
        Article::query()->findOrFail($article)->trackEvent('shared', ['network' => 'mastodon']);

        return 'ok';
    });

    cairnTest()->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/122.0.0.0 Safari/537.36'])
        ->get('/share/'.articleKey($article));

    $row = (array) articleEntries()->where('name', 'shared')->first();

    expect($row['subject_type'] ?? null)->toBe(Article::class)
        ->and(json_decode(asString($row['properties'] ?? ''), true))->toBe(['network' => 'mastodon']);
});

it('records a conversion against a model', function (): void {
    $article = Article::query()->create(['title' => 'Cairn']);

    Route::middleware('web')->get('/buy/{article}', function (string $article): string {
        Article::query()->findOrFail($article)->trackConversion('subscribed', 12.5);

        return 'ok';
    });

    cairnTest()->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/122.0.0.0 Safari/537.36'])
        ->get('/buy/'.articleKey($article));

    $row = (array) articleEntries()->where('type', 'conversion')->first();

    expect($row['name'] ?? null)->toBe('subscribed')
        ->and(columnFloat($row['value'] ?? null))->toBe(12.5);
});

it('uses the morph alias so a class rename does not orphan history', function (): void {
    Relation::morphMap(['article' => Article::class]);

    $article = Article::query()->create(['title' => 'Cairn']);

    readArticle(articleKey($article));

    expect(articleEntries()->value('subject_type'))->toBe('article');

    Relation::morphMap([], false);
});

/**
 * A model event triggered during a request the visitor asked not to have
 * measured is still that visitor's request.
 */
it('records nothing for a model when the visitor sent Do Not Track', function (): void {
    $article = Article::query()->create(['title' => 'Cairn']);

    readArticle(articleKey($article), ['DNT' => '1']);

    expect(articleEntries()->count())->toBe(0);
});

it('records nothing for a model when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);

    $article = Article::query()->create(['title' => 'Cairn']);

    readArticle(articleKey($article));

    expect(articleEntries()->count())->toBe(0);
});

it('does not break the response when analytics storage is gone', function (): void {
    $article = Article::query()->create(['title' => 'Cairn']);

    cairnStorageGone();

    cairnTest()->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/122.0.0.0 Safari/537.36'])
        ->get('/articles/'.articleKey($article))
        ->assertOk()
        ->assertSee('ok');
});

/*
|--------------------------------------------------------------------------
| Reading back
|--------------------------------------------------------------------------
|
| The README has sold Eloquent models as first-class subjects since 1.0, and
| until 1.2 nothing read them back: trackView() wrote a subject type and id on
| every entry and no dimension, widget or helper ever looked at them.
|
*/

it('reads back the views it recorded, through the rollup', function (): void {
    $article = Article::query()->create(['title' => 'How we built it']);

    cairnTest()->get('/articles/'.articleKey($article));
    cairnTest()->get('/articles/'.articleKey($article));

    rollupToday();

    expect($article->views())->toBe(2);
});

/**
 * The read path is the report builder, which reads cairn_aggregates and
 * nothing else. A helper that scanned raw entries would be the one place in
 * the package that broke the rule the whole design rests on.
 */
it('never scans raw entries to answer', function (): void {
    $article = Article::query()->create(['title' => 'How we built it']);

    cairnTest()->get('/articles/'.articleKey($article));
    rollupToday();

    $touched = [];

    DB::listen(function (QueryExecuted $query) use (&$touched): void {
        if (str_contains($query->sql, Tables::entries())) {
            $touched[] = $query->sql;
        }
    });

    $article->views();

    expect($touched)->toBe([]);
});

/**
 * Views and other events are counted separately. A subject never reaches a
 * pageview — trackView() records an event named "viewed" — so without the
 * split an article's event count would silently include its views.
 */
it('counts views apart from other events', function (): void {
    $article = Article::query()->create(['title' => 'How we built it']);

    Route::middleware('web')->get('/articles/{article}/share', function (string $article): string {
        Article::query()->findOrFail($article)->trackEvent('shared');

        return 'ok';
    });

    cairnTest()->get('/articles/'.articleKey($article));
    cairnTest()->get('/articles/'.articleKey($article).'/share');

    rollupToday();

    expect($article->views())->toBe(1)
        ->and($article->analyticsEvents())->toBe(1);
});

it('answers zero for a model nothing was ever recorded against', function (): void {
    $article = Article::query()->create(['title' => 'Unread']);

    expect($article->views())->toBe(0);
});

/**
 * The key is the morph alias, so renaming the class does not orphan history —
 * the same guarantee the write side already made.
 */
it('reads back under the morph alias, not the class name', function (): void {
    Relation::morphMap(['article' => Article::class]);

    $article = Article::query()->create(['title' => 'How we built it']);

    expect($article->analyticsKey())->toBe('article:'.articleKey($article));

    Relation::morphMap([], false);
});

/*
|--------------------------------------------------------------------------
| Top content
|--------------------------------------------------------------------------
*/

it('names the models rather than numbering them', function (): void {
    $article = Article::query()->create(['title' => 'How we built it']);

    cairnTest()->get('/articles/'.articleKey($article));
    rollupToday();

    $rows = app(TopContent::class)->rows(new Filters(range: 'today'));

    // No analyticsLabel() on the stand-in model, so the fallback applies.
    expect($rows->first()?->dimension('subject'))->toBe('Article #'.articleKey($article))
        ->and($rows->first()?->metric(Metric::Pageviews))->toBe(1.0);
});

/**
 * A model may name itself. Cairn cannot guess whether that is a title, a slug
 * or a reference number.
 */
it('prefers the model own label when it defines one', function (): void {
    $article = LabelledArticle::query()->create(['title' => 'How we built it']);

    cairnTest()->get('/labelled/'.articleKey($article));
    rollupToday();

    expect(app(TopContent::class)->rows(new Filters(range: 'today'))->first()?->dimension('subject'))
        ->toBe('How we built it');
});

/**
 * The whole point of resolving labels by type rather than by row. A ten-row
 * table must not become ten queries because somebody opened the dashboard.
 */
it('resolves labels with one query per subject type', function (): void {
    foreach (['One', 'Two', 'Three'] as $title) {
        $article = Article::query()->create(['title' => $title]);
        cairnTest()->get('/articles/'.articleKey($article));
    }

    rollupToday();

    $queries = 0;

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, '"cairn_test_articles"') || str_contains($query->sql, '`cairn_test_articles`')) {
            $queries++;
        }
    });

    $rows = app(TopContent::class)->rows(new Filters(range: 'today'));

    expect($rows)->toHaveCount(3)
        ->and($queries)->toBe(1);
});

/**
 * The views happened. Dropping the row because the model was deleted would
 * quietly change a total that was correct when it was measured.
 */
it('keeps a row whose model has since been deleted', function (): void {
    $article = Article::query()->create(['title' => 'Deleted later']);
    $key = articleKey($article);

    cairnTest()->get('/articles/'.$key);
    rollupToday();

    $article->delete();

    expect(app(TopContent::class)->rows(new Filters(range: 'today'))->first()?->dimension('subject'))
        ->toBe(Article::class.':'.$key);
});

it('reads back the conversions it recorded', function (): void {
    $article = Article::query()->create(['title' => 'How we built it']);

    Route::middleware('web')->get('/articles/{article}/buy', function (string $article): string {
        Article::query()->findOrFail($article)->trackConversion('purchase', 9.99);

        return 'ok';
    });

    cairnTest()->get('/articles/'.articleKey($article).'/buy');
    rollupToday();

    expect($article->analyticsConversions())->toBe(1);
});

/**
 * A subject type that no longer maps to a model — a class deleted between
 * releases, or a morph alias removed from the map — leaves the panel showing
 * the stored key rather than taking the dashboard down.
 */
it('shows the raw key when a subject type is no longer a model', function (): void {
    seedSubjectAggregate('App\\Models\\Gone:42');

    expect(app(TopContent::class)->rows(new Filters(range: 'today'))->first()?->dimension('subject'))
        ->toBe('App\\Models\\Gone:42');
});

/**
 * A table dropped underneath a subject type that still resolves to a class.
 * The query throws, the panel reports it and shows keys; the other seventeen
 * panels are still worth drawing.
 */
it('survives a subject whose table has been dropped', function (): void {
    seedSubjectAggregate(Tableless::class.':42');

    expect(app(TopContent::class)->rows(new Filters(range: 'today'))->first()?->dimension('subject'))
        ->toBe(Tableless::class.':42');
});

/**
 * A model that cannot produce a usable key cannot be labelled, so the row
 * keeps its stored key rather than being dropped or labelled wrongly.
 */
it('keeps the key for a model with no usable primary key', function (): void {
    Article::query()->create(['title' => 'Keyless']);

    seedSubjectAggregate(Keyless::class.':1');

    expect(app(TopContent::class)->rows(new Filters(range: 'today'))->first()?->dimension('subject'))
        ->toBe(Keyless::class.':1');
});
