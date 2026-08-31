<?php

declare(strict_types=1);

namespace Divoto\Cairn\Geo;

use Closure;
use Divoto\Cairn\Exceptions\GeoDatabaseDownloadException as Failed;
use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Fetches a GeoLite2 database from MaxMind and installs it on disk.
 *
 * This exists because the alternative was a documentation page telling people
 * to download a file in a browser and move it into place — which they then do
 * once, and never again. An old geo database is quietly wrong rather than
 * broken: address blocks get reallocated and visitors drift into the wrong
 * country with nothing to indicate it.
 *
 * **This is the one part of Cairn that makes a network call, and it only ever
 * runs from the command line.** Nothing in the request path constructs this
 * class. The lookups themselves are always local — see
 * {@see MaxMindGeoResolver} for why that is not negotiable.
 *
 * The transport is a constructor seam so the download can be tested without
 * reaching MaxMind. It is deliberately curl rather than Laravel's HTTP client:
 * that client needs Guzzle, which Cairn does not require, and adding a
 * dependency for one administrative command would be a poor trade.
 */
final readonly class MaxMindDownloader
{
    /**
     * The free editions. Country is what Cairn wants; City is only useful if
     * `geo_precision` has deliberately been raised, and ASN is here because
     * MaxMind offers it, not because Cairn reads it.
     */
    public const EDITIONS = ['GeoLite2-Country', 'GeoLite2-City', 'GeoLite2-ASN'];

    private const ENDPOINT = 'https://download.maxmind.com/geoip/databases/%s/download?suffix=%s';

    /**
     * @var Closure(string, string, string, string, ?Closure): void
     */
    private Closure $transport;

    /**
     * @param  null|(callable(string, string, string, string, ?Closure): void)  $transport
     *                                                                                      Receives ($url, $accountId, $licenseKey, $sink, $onProgress) and
     *                                                                                      writes the response body to $sink, or throws.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null
            ? $this->curl(...)
            : Closure::fromCallable($transport);
    }

    /**
     * Download an edition and install it at $destination.
     *
     * The existing database is replaced only once a new one has been fetched,
     * verified against MaxMind's published checksum and unpacked. A failure at
     * any point leaves the current database exactly as it was — an analytics
     * dashboard losing its Countries panel because a cron job hit a network
     * blip would be a poor way to find out about the blip.
     *
     * @param  null|(callable(int, int): void)  $onProgress  Receives (bytesSoFar, bytesTotal).
     * @return array{path: string, bytes: int, edition: string, built: ?string, replaced: bool}
     */
    public function download(
        string $edition,
        string $destination,
        string $accountId,
        string $licenseKey,
        ?callable $onProgress = null,
    ): array {
        if (! in_array($edition, self::EDITIONS, true)) {
            throw Failed::unknownEdition($edition, implode(', ', self::EDITIONS));
        }

        if ($accountId === '' || $licenseKey === '') {
            throw Failed::noCredentials();
        }

        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw Failed::directoryNotWritable($directory);
        }

        if (! is_writable($directory)) {
            throw Failed::directoryNotWritable($directory);
        }

        $workspace = $this->workspace($directory);

        try {
            $archive = $workspace.'/db.tar.gz';

            // The checksum first: it is a few bytes, and fetching it before
            // the 60MB City database means bad credentials fail in a moment
            // rather than at the end of a long download.
            ($this->transport)(
                sprintf(self::ENDPOINT, $edition, 'tar.gz.sha256'),
                $accountId,
                $licenseKey,
                $workspace.'/db.sha256',
                null,
            );

            $expected = $this->publishedChecksum($workspace.'/db.sha256');

            ($this->transport)(
                sprintf(self::ENDPOINT, $edition, 'tar.gz'),
                $accountId,
                $licenseKey,
                $archive,
                $onProgress === null ? null : Closure::fromCallable($onProgress),
            );

            $actual = hash_file('sha256', $archive);

            if ($actual !== $expected) {
                throw Failed::checksumMismatch($expected, is_string($actual) ? $actual : 'unreadable');
            }

            $database = $this->unpack($archive, $workspace);
            $replaced = is_file($destination);

            $bytes = (int) filesize($database);
            $built = $this->buildDate($database);

            $this->install($database, $destination);

            return [
                'path' => $destination,
                'bytes' => $bytes,
                'edition' => $edition,
                'built' => $built,
                'replaced' => $replaced,
            ];
        } finally {
            $this->deleteTree($workspace);
        }
    }

    /**
     * A scratch directory beside the destination.
     *
     * Beside, rather than in the system temp directory, so the final move is a
     * rename within one filesystem: atomic, and with no window where the
     * database is half-written. Copying 60MB across a device boundary would
     * have one.
     */
    private function workspace(string $directory): string
    {
        $path = $directory.'/.cairn-geoip-'.bin2hex(random_bytes(6));

        if (! @mkdir($path, 0o700) && ! is_dir($path)) {
            throw Failed::directoryNotWritable($directory);
        }

        return $path;
    }

    /**
     * MaxMind publishes `<sha256>  <filename>`.
     */
    private function publishedChecksum(string $path): string
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents) || preg_match('/\b([a-f0-9]{64})\b/i', $contents, $matches) !== 1) {
            throw Failed::unreachable('the published checksum was missing or unreadable.');
        }

        return strtolower($matches[1]);
    }

    /**
     * Unpack the tarball and return the path to the .mmdb inside it.
     *
     * The archive holds a single dated directory — `GeoLite2-Country_20260803`
     * — with the database, a licence and a copyright notice in it.
     */
    private function unpack(string $archive, string $workspace): string
    {
        $target = $workspace.'/unpacked';

        try {
            (new PharData($archive))->extractTo($target, null, true);
        } catch (Throwable $e) {
            throw Failed::notAnArchive($e->getMessage());
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'mmdb') {
                return $file->getPathname();
            }
        }

        throw Failed::noDatabaseInArchive();
    }

    /**
     * The build date MaxMind stamped into the directory name, as ISO-8601.
     *
     * Reported so a deployer can see at a glance whether a scheduled update is
     * actually running, which a file mtime would not tell them — a re-download
     * of an unchanged database refreshes the mtime either way.
     */
    private function buildDate(string $database): ?string
    {
        if (preg_match('/_(\d{4})(\d{2})(\d{2})/', basename(dirname($database)), $m) !== 1) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
    }

    /**
     * Move the new database into place.
     */
    private function install(string $database, string $destination): void
    {
        if (! @rename($database, $destination)) {
            throw Failed::couldNotInstall($destination);
        }

        @chmod($destination, 0o644);
    }

    private function deleteTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        /** @var iterable<SplFileInfo> $contents */
        $contents = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($contents as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * The default transport.
     *
     * Streams to disk rather than into memory: the City database is around
     * 60MB, and a package that runs on shared hosting should not need
     * `memory_limit` raised to update a geo database.
     *
     * @param  null|Closure(int, int): void  $onProgress
     */
    private function curl(
        string $url,
        string $accountId,
        string $licenseKey,
        string $sink,
        ?Closure $onProgress,
    ): void {
        // @codeCoverageIgnoreStart
        // Every supported PHP build in this package's test matrix ships
        // ext-curl, and curl_init() does not fail for a well-formed URL — both
        // guards are real, but neither is reachable from a test.
        if (! function_exists('curl_init')) {
            throw Failed::unreachable('ext-curl is not installed. Download the database manually instead — '
                .'see documentation/geolocation.md.');
        }
        // @codeCoverageIgnoreEnd

        $handle = @fopen($sink, 'wb');

        if ($handle === false) {
            throw Failed::directoryNotWritable(dirname($sink));
        }

        $curl = curl_init($url);

        // @codeCoverageIgnoreStart
        if ($curl === false) {
            fclose($handle);

            throw Failed::unreachable('curl could not be initialised.');
        }
        // @codeCoverageIgnoreEnd

        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $accountId.':'.$licenseKey,
            CURLOPT_USERAGENT => 'cairn (+https://github.com/divoto/cairn)',
            CURLOPT_NOPROGRESS => ! $onProgress instanceof Closure,
            CURLOPT_PROGRESSFUNCTION => static function (
                mixed $resource,
                int $total,
                int $sofar
            ) use ($onProgress): int {
                if ($onProgress instanceof Closure && $total > 0) {
                    $onProgress($sofar, $total);
                }

                return 0;
            },
        ]);

        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        curl_close($curl);
        fclose($handle);

        if ($ok === false) {
            @unlink($sink);

            throw Failed::unreachable($error === '' ? 'the connection failed.' : $error);
        }

        if ($status !== 200) {
            @unlink($sink);

            throw Failed::rejected($status);
        }
    }
}
