<?php

declare(strict_types=1);

use Divoto\Cairn\Concerns\HasAnalytics;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A host-application model, standing in for whatever a user would attach the
 * trait to.
 */
final class Article extends Model
{
    use HasAnalytics;

    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function (): void {
    Schema::create('articles', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
    });

    Route::middleware('web')->get('/articles/{article}', function (string $article): string {
        Article::query()->findOrFail($article)->trackView();

        return 'ok';
    })->name('articles.show');
});

/**
 * A model's key as a string, for building URLs.
 */
function articleKey(Article $article): string
{
    $key = $article->getKey();

    return is_scalar($key) ? (string) $key : '';
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
        ->and($row['subject_id'] ?? null)->toBe('1')
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

    Schema::connection(Tables::connection())->drop(Tables::entries());

    cairnTest()->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/122.0.0.0 Safari/537.36'])
        ->get('/articles/'.articleKey($article))
        ->assertOk()
        ->assertSee('ok');
});
