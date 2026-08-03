<?php

declare(strict_types=1);

use Divoto\Cairn\Exceptions\GeoDatabaseDownloadException;
use Divoto\Cairn\Geo\MaxMindDownloader;
use Divoto\Cairn\Geo\MaxMindGeoResolver;
use Divoto\Cairn\Geo\NullGeoResolver;
use GeoIp2\Database\Reader;
use Illuminate\Testing\PendingCommand;

/*
|--------------------------------------------------------------------------
| Downloading a GeoLite2 database
|--------------------------------------------------------------------------
|
| cairn:geoip is the only part of Cairn that makes a network call, and it only
| ever runs from the command line. These tests never reach MaxMind: the
| transport is a constructor seam, and the fixtures below are real tar.gz
| archives built on the fly, so the unpacking is genuinely exercised.
|
*/

/**
 * Somewhere to work, cleaned up between tests.
 *
 * Held in a static rather than on the test case: Pest's TestCase has no such
 * property, and level 9 is right to say so.
 */
function geoipWorkspace(?string $set = null): string
{
    static $path = '';

    if ($set !== null) {
        $path = $set;
    }

    return $path;
}

function makeGeoipWorkspace(): string
{
    $path = sys_get_temp_dir().'/cairn-geoip-test-'.bin2hex(random_bytes(6));
    mkdir($path.'/geoip', 0o755, true);

    return geoipWorkspace($path);
}

/**
 * Where a Country database lands by default in these tests.
 */
function geoipTarget(): string
{
    return geoipWorkspace().'/geoip/GeoLite2-Country.mmdb';
}

function deleteGeoipWorkspace(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    /** @var iterable<SplFileInfo> $items */
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/**
 * Build a tar.gz shaped like MaxMind's: one dated directory containing the
 * database alongside its licence files.
 */
function geoipArchive(string $in, string $edition = 'GeoLite2-Country', string $date = '20260803', string $contents = 'fake mmdb payload'): string
{
    $staging = $in.'/staging';
    $inner = $staging.'/'.$edition.'_'.$date;
    mkdir($inner, 0o755, true);

    file_put_contents($inner.'/'.$edition.'.mmdb', $contents);
    file_put_contents($inner.'/LICENSE.txt', 'MaxMind EULA');
    file_put_contents($inner.'/COPYRIGHT.txt', 'copyright');

    $tar = $in.'/fixture.tar';
    (new PharData($tar))->buildFromDirectory($staging);
    (new PharData($tar))->compress(Phar::GZ);

    unlink($tar);

    return $in.'/fixture.tar.gz';
}

/**
 * A transport that serves a prepared archive instead of calling MaxMind.
 *
 * @param  array{status?: int, checksum?: string, archive?: string}  $options
 */
function geoipTransport(string $archive, array $options = []): Closure
{
    return function (string $url, string $account, string $key, string $sink, ?Closure $progress) use ($archive, $options): void {
        if (isset($options['status'])) {
            throw GeoDatabaseDownloadException::rejected($options['status']);
        }

        if (str_ends_with($url, 'sha256')) {
            $sum = $options['checksum'] ?? hash_file('sha256', $archive);
            file_put_contents($sink, $sum.'  GeoLite2.tar.gz\n');

            return;
        }

        copy($options['archive'] ?? $archive, $sink);

        if ($progress instanceof Closure) {
            $size = (int) filesize($sink);
            $progress((int) ($size / 2), $size);
            $progress($size, $size);
        }
    };
}

/**
 * artisan() is declared as PendingCommand|int, and only the former can be
 * asserted against. Same guard as CommandsTest uses.
 *
 * @param  array<string, string>  $arguments
 */
function geoip(array $arguments = []): PendingCommand
{
    $command = cairnTest()->artisan('cairn:geoip', $arguments);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand.');
    }

    return $command;
}

beforeEach(function (): void {
    makeGeoipWorkspace();
});

afterEach(function (): void {
    deleteGeoipWorkspace(geoipWorkspace());
});

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('downloads, verifies and installs a database', function (): void {
    $archive = geoipArchive(geoipWorkspace());

    $result = (new MaxMindDownloader(geoipTransport($archive)))
        ->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key');

    expect($result['path'])->toBe(geoipTarget())
        ->and($result['edition'])->toBe('GeoLite2-Country')
        ->and($result['replaced'])->toBeFalse()
        ->and(is_file(geoipTarget()))->toBeTrue()
        ->and(file_get_contents(geoipTarget()))->toBe('fake mmdb payload');
});

it('reports the build date MaxMind stamped into the archive', function (): void {
    $archive = geoipArchive(geoipWorkspace(), date: '20260731');

    $result = (new MaxMindDownloader(geoipTransport($archive)))
        ->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key');

    expect($result['built'])->toBe('2026-07-31');
});

it('reports the size of the installed database', function (): void {
    $archive = geoipArchive(geoipWorkspace(), contents: str_repeat('x', 4096));

    $result = (new MaxMindDownloader(geoipTransport($archive)))
        ->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key');

    expect($result['bytes'])->toBe(4096);
});

it('says when it replaced an existing database rather than installing a new one', function (): void {
    file_put_contents(geoipTarget(), 'the old database');

    $archive = geoipArchive(geoipWorkspace());

    $result = (new MaxMindDownloader(geoipTransport($archive)))
        ->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key');

    expect($result['replaced'])->toBeTrue()
        ->and(file_get_contents(geoipTarget()))->toBe('fake mmdb payload');
});

it('creates the directory when it does not exist yet', function (): void {
    $destination = geoipWorkspace().'/deeply/nested/GeoLite2-Country.mmdb';
    $archive = geoipArchive(geoipWorkspace());

    (new MaxMindDownloader(geoipTransport($archive)))
        ->download('GeoLite2-Country', $destination, '123456', 'a-key');

    expect(is_file($destination))->toBeTrue();
});

it('reports progress while downloading', function (): void {
    $archive = geoipArchive(geoipWorkspace());
    $seen = [];

    (new MaxMindDownloader(geoipTransport($archive)))->download(
        'GeoLite2-Country',
        geoipTarget(),
        '123456',
        'a-key',
        function (int $sofar, int $total) use (&$seen): void {
            $seen[] = [$sofar, $total];
        }
    );

    expect($seen)->not->toBeEmpty()
        ->and($seen[count($seen) - 1][0])->toBe($seen[count($seen) - 1][1]);
});

/*
|--------------------------------------------------------------------------
| Nothing is replaced until the new database is known good
|--------------------------------------------------------------------------
|
| A cron job that hits a network blip must not cost a working Countries panel.
|
*/

it('leaves an existing database untouched when the checksum does not match', function (): void {
    file_put_contents(geoipTarget(), 'the old database');

    $archive = geoipArchive(geoipWorkspace());
    $downloader = new MaxMindDownloader(geoipTransport($archive, ['checksum' => str_repeat('a', 64)]));

    expect(fn (): array => $downloader->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key'))
        ->toThrow(GeoDatabaseDownloadException::class, 'corrupt');

    expect(file_get_contents(geoipTarget()))->toBe('the old database');
});

it('leaves an existing database untouched when MaxMind rejects the credentials', function (): void {
    file_put_contents(geoipTarget(), 'the old database');

    $downloader = new MaxMindDownloader(geoipTransport('unused', ['status' => 401]));

    expect(fn (): array => $downloader->download('GeoLite2-Country', geoipTarget(), '1', 'wrong'))
        ->toThrow(GeoDatabaseDownloadException::class, 'rejected the credentials');

    expect(file_get_contents(geoipTarget()))->toBe('the old database');
});

it('leaves an existing database untouched when the archive holds no database', function (): void {
    file_put_contents(geoipTarget(), 'the old database');

    $staging = geoipWorkspace().'/empty';
    mkdir($staging.'/GeoLite2-Country_20260803', 0o755, true);
    file_put_contents($staging.'/GeoLite2-Country_20260803/LICENSE.txt', 'just a licence');

    $tar = geoipWorkspace().'/empty.tar';
    (new PharData($tar))->buildFromDirectory($staging);
    (new PharData($tar))->compress(Phar::GZ);
    unlink($tar);

    $downloader = new MaxMindDownloader(geoipTransport(geoipWorkspace().'/empty.tar.gz'));

    expect(fn (): array => $downloader->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key'))
        ->toThrow(GeoDatabaseDownloadException::class, 'no .mmdb file');

    expect(file_get_contents(geoipTarget()))->toBe('the old database');
});

it('leaves an existing database untouched when the download is not an archive', function (): void {
    file_put_contents(geoipTarget(), 'the old database');

    $notAnArchive = geoipWorkspace().'/garbage.tar.gz';
    file_put_contents($notAnArchive, 'this is not a tarball');

    $downloader = new MaxMindDownloader(geoipTransport($notAnArchive));

    expect(fn (): array => $downloader->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key'))
        ->toThrow(GeoDatabaseDownloadException::class, 'could not be unpacked');

    expect(file_get_contents(geoipTarget()))->toBe('the old database');
});

/**
 * A half-written 60MB file in the geoip directory would be found later by
 * somebody with no idea what put it there.
 */
it('leaves no scratch files behind, whether it succeeds or fails', function (bool $succeed): void {
    $archive = geoipArchive(geoipWorkspace());
    $options = $succeed ? [] : ['checksum' => str_repeat('b', 64)];

    try {
        (new MaxMindDownloader(geoipTransport($archive, $options)))
            ->download('GeoLite2-Country', geoipTarget(), '123456', 'a-key');
    } catch (GeoDatabaseDownloadException) {
        // The point of the test is what is left on disk afterwards.
    }

    $leftovers = array_values(array_filter(
        (array) scandir(geoipWorkspace().'/geoip'),
        fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, '.cairn-geoip-')
    ));

    expect($leftovers)->toBe([]);
})->with(['succeeds' => [true], 'fails' => [false]]);

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses an edition that does not exist', function (): void {
    expect(fn (): array => (new MaxMindDownloader(geoipTransport('unused')))
        ->download('GeoLite2-Moon', geoipTarget(), '123456', 'a-key'))
        ->toThrow(GeoDatabaseDownloadException::class, 'is not a GeoLite2 edition');
});

it('refuses to start without credentials', function (string $account, string $key): void {
    expect(fn (): array => (new MaxMindDownloader(geoipTransport('unused')))
        ->download('GeoLite2-Country', geoipTarget(), $account, $key))
        ->toThrow(GeoDatabaseDownloadException::class, 'No MaxMind credentials');
})->with([
    'no account' => ['', 'a-key'],
    'no key' => ['123456', ''],
]);

it('explains each way MaxMind can say no', function (int $status, string $expected): void {
    expect(GeoDatabaseDownloadException::rejected($status)->getMessage())->toContain($expected);
})->with([
    'bad credentials' => [401, 'rejected the credentials'],
    'forbidden' => [403, 'rejected the credentials'],
    'no such edition' => [404, 'no such database'],
    'rate limited' => [429, 'rate limiting'],
    'anything else' => [503, 'HTTP 503'],
]);

/**
 * The account ID being a number rather than an email address is the single
 * most common way this goes wrong, so the message says so.
 */
it('names the likely mistake when credentials are rejected', function (): void {
    expect(GeoDatabaseDownloadException::rejected(401)->getMessage())
        ->toContain('not an email address');
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('tells a deployer how to get credentials when there are none', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => null, 'license_key' => null]);

    geoip()
        ->expectsOutputToContain('geolite2/signup')
        ->expectsOutputToContain('MAXMIND_ACCOUNT_ID')
        ->assertExitCode(1);
});

/**
 * Laravel merges only top-level config keys, so an application that published
 * config/cairn.php before this option existed has a `privacy` array without
 * `maxmind` in it — and the .env values would be read by nothing at all.
 */
it('warns when a published config predates the maxmind option', function (): void {
    config()->set('cairn.privacy.maxmind');

    geoip()
        ->expectsOutputToContain('predates this option')
        ->assertExitCode(1);
});

it('does not warn about the config when the option is present', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => null, 'license_key' => null]);

    geoip()
        ->doesntExpectOutputToContain('predates this option')
        ->assertExitCode(1);
});

it('takes credentials from the command line so they need not be stored', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => null, 'license_key' => null]);

    $archive = geoipArchive(geoipWorkspace());
    app()->instance(MaxMindDownloader::class, new MaxMindDownloader(geoipTransport($archive)));

    geoip([
        '--account-id' => '123456',
        '--key' => 'a-key',
        '--path' => geoipTarget(),
    ])->assertExitCode(0);

    expect(is_file(geoipTarget()))->toBeTrue();
});

it('takes credentials from configuration', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => '123456', 'license_key' => 'a-key']);

    $archive = geoipArchive(geoipWorkspace());
    app()->instance(MaxMindDownloader::class, new MaxMindDownloader(geoipTransport($archive)));

    geoip(['--path' => geoipTarget()])->assertExitCode(0);

    expect(is_file(geoipTarget()))->toBeTrue();
});

it('reports a failure as a message rather than a stack trace', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => '1', 'license_key' => 'wrong']);

    app()->instance(
        MaxMindDownloader::class,
        new MaxMindDownloader(geoipTransport('unused', ['status' => 401]))
    );

    geoip(['--path' => geoipTarget()])
        ->expectsOutputToContain('rejected the credentials')
        ->assertExitCode(1);
});

/**
 * Downloading the City database must not overwrite the Country file that
 * `geo_database` points at, leaving the configuration describing a file whose
 * contents no longer match its name.
 */
it('names the file after the edition rather than overwriting the configured one', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => '123456', 'license_key' => 'a-key']);
    config()->set('cairn.privacy.geo_database', geoipTarget());
    config()->set('cairn.privacy.geo_precision', 'city');

    $archive = geoipArchive(geoipWorkspace(), edition: 'GeoLite2-City');
    app()->instance(MaxMindDownloader::class, new MaxMindDownloader(geoipTransport($archive)));

    geoip(['--edition' => 'GeoLite2-City'])->assertExitCode(0);

    expect(is_file(dirname(geoipTarget()).'/GeoLite2-City.mmdb'))->toBeTrue()
        ->and(is_file(geoipTarget()))->toBeFalse();
});

it('says what is still needed after downloading', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => '123456', 'license_key' => 'a-key']);
    config()->set('cairn.privacy.geo_resolver', NullGeoResolver::class);

    $archive = geoipArchive(geoipWorkspace());
    app()->instance(MaxMindDownloader::class, new MaxMindDownloader(geoipTransport($archive)));

    geoip(['--path' => geoipTarget()])
        ->expectsOutputToContain('Still to do')
        ->expectsOutputToContain('geo_resolver')
        ->assertExitCode(0);
});

it('says nothing more is needed when everything is already wired up', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => '123456', 'license_key' => 'a-key']);
    config()->set('cairn.privacy.geo_resolver', MaxMindGeoResolver::class);
    config()->set('cairn.privacy.geo_database', geoipTarget());

    $archive = geoipArchive(geoipWorkspace());
    app()->instance(MaxMindDownloader::class, new MaxMindDownloader(geoipTransport($archive)));

    geoip()
        ->doesntExpectOutputToContain('Still to do')
        ->assertExitCode(0);
})->skip(fn (): bool => ! class_exists(Reader::class), 'geoip2/geoip2 is not installed');

it('warns that a city database holds more than country precision will store', function (): void {
    config()->set('cairn.privacy.maxmind', ['account_id' => '123456', 'license_key' => 'a-key']);
    config()->set('cairn.privacy.geo_precision', 'country');

    $archive = geoipArchive(geoipWorkspace(), edition: 'GeoLite2-City');
    app()->instance(MaxMindDownloader::class, new MaxMindDownloader(geoipTransport($archive)));

    geoip([
        '--edition' => 'GeoLite2-City',
        '--path' => geoipWorkspace().'/geoip/GeoLite2-City.mmdb',
    ])
        ->expectsOutputToContain('more than Cairn will store')
        ->assertExitCode(0);
});

/*
|--------------------------------------------------------------------------
| The invariant
|--------------------------------------------------------------------------
*/

/**
 * Cairn makes exactly one kind of network call, from exactly one place, and
 * only from the command line. If the downloader ever became reachable from a
 * request, a visitor's pageview could end up waiting on MaxMind.
 */
it('is never constructed from the request path', function (): void {
    $roots = ['Recording', 'Middleware', 'Http', 'Listeners', 'Geo/MaxMindGeoResolver.php'];

    $sources = [];

    foreach ($roots as $root) {
        $path = __DIR__.'/../../src/'.$root;

        if (is_file($path)) {
            $sources[] = $path;

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[] = $file->getPathname();
            }
        }
    }

    expect($sources)->not->toBeEmpty();

    foreach ($sources as $source) {
        expect((string) file_get_contents($source))->not->toContain('MaxMindDownloader');
    }
});
