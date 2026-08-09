<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Divoto\Cairn\Exceptions\GeoDatabaseDownloadException;
use Divoto\Cairn\Geo\MaxMindDownloader;
use Divoto\Cairn\Geo\MaxMindGeoResolver;
use GeoIp2\Database\Reader;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Download a GeoLite2 database from MaxMind and install it.
 *
 * The setup this replaces was: register, generate a licence key, find the
 * right download link, unpack a tarball, move the file, guess at the path.
 * Six steps in a browser, done once and then never repeated — which is the
 * real problem, because a stale geo database is silently wrong rather than
 * visibly broken.
 *
 * This command is safe to schedule. GeoLite2 is rebuilt on Tuesdays and
 * Fridays; anything more frequent than weekly is wasted, and MaxMind will
 * eventually rate limit it.
 */
final class GeoipCommand extends Command
{
    protected $signature = 'cairn:geoip
        {--edition=GeoLite2-Country : Which GeoLite2 database to download}
        {--account-id= : MaxMind account ID, if it is not in the environment}
        {--key= : MaxMind licence key, if it is not in the environment}
        {--path= : Where to write the database, overriding the configured location}';

    protected $description = 'Download the MaxMind GeoLite2 database used for country reporting';

    public function handle(Config $config, MaxMindDownloader $downloader): int
    {
        $edition = $this->option('edition');
        $edition = is_string($edition) ? $edition : 'GeoLite2-Country';

        $accountId = $this->credential('account-id', 'cairn.privacy.maxmind.account_id');
        $licenseKey = $this->credential('key', 'cairn.privacy.maxmind.license_key');

        if ($accountId === '' || $licenseKey === '') {
            $this->credentialsHelp($config);

            return self::FAILURE;
        }

        $destination = $this->destination($config, $edition);

        $this->line('  Downloading <options=bold>'.$edition.'</> from MaxMind');

        $bar = null;

        try {
            $result = $downloader->download(
                $edition,
                $destination,
                $accountId,
                $licenseKey,
                function (int $sofar, int $total) use (&$bar): void {
                    if (! $bar instanceof ProgressBar) {
                        $bar = $this->output->createProgressBar($total);
                        $bar->setFormat('  %bar% %percent:3s%%  %downloaded%');
                    }

                    $bar->setMessage($this->size($sofar).' / '.$this->size($total), 'downloaded');
                    $bar->setProgress($sofar);
                }
            );

            $bar?->finish();
            $this->newLine(2);
        } catch (GeoDatabaseDownloadException $e) {
            $bar?->clear();
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s %s (%s%s)',
            $result['replaced'] ? 'Replaced' : 'Installed',
            $this->relative($result['path']),
            $this->size($result['bytes']),
            $result['built'] === null ? '' : ', built '.$result['built'],
        ));

        $this->afterthoughts($config, $result['path'], $edition);

        return self::SUCCESS;
    }

    /**
     * A credential from the command line, falling back to configuration.
     *
     * Configuration rather than env() directly: this command is the sort of
     * thing that ends up in a scheduler, and `env()` outside a config file
     * returns null the moment someone runs `config:cache`. Which would make
     * the failure appear in production only, and only after a deploy step
     * unrelated to analytics.
     */
    private function credential(string $option, string $key): string
    {
        $fromOption = $this->option($option);

        if (is_string($fromOption) && trim($fromOption) !== '') {
            return trim($fromOption);
        }

        $configured = config($key);

        return is_string($configured) ? trim($configured) : '';
    }

    private function credentialsHelp(Config $config): void
    {
        $this->components->error('No MaxMind credentials found.');

        $this->line('  GeoLite2 is free, but MaxMind requires an account to download it.');
        $this->newLine();
        $this->line('    1. Sign up at <options=bold>https://www.maxmind.com/en/geolite2/signup</>');
        $this->line('    2. Under "Manage License Keys", create a key');
        $this->line('    3. Add both values to your .env:');
        $this->newLine();
        $this->line('       <fg=gray>MAXMIND_ACCOUNT_ID=123456</>');
        $this->line('       <fg=gray>MAXMIND_LICENSE_KEY=your_key_here</>');
        $this->newLine();
        $this->line('  The account ID is the number on your account page, not your email address.');

        // Laravel merges only top-level config keys, so an application that
        // published config/cairn.php before this option existed has a
        // "privacy" array without it — and the .env above would be read by
        // nothing at all. That failure is invisible without being told.
        if (! is_array($config->get('cairn.privacy.maxmind'))) {
            $this->newLine();
            $this->components->warn('Your published config/cairn.php predates this option.');
            $this->line('  Add this inside the <options=bold>privacy</> array, or the .env values are ignored:');
            $this->newLine();
            $this->line("       <fg=gray>'maxmind' => [</>");
            $this->line("       <fg=gray>    'account_id' => env('MAXMIND_ACCOUNT_ID'),</>");
            $this->line("       <fg=gray>    'license_key' => env('MAXMIND_LICENSE_KEY'),</>");
            $this->line('       <fg=gray>],</>');
        }

        $this->newLine();
        $this->line('  To avoid storing them at all, pass <options=bold>--account-id</> and <options=bold>--key</>.');
        $this->newLine();
    }

    /**
     * Where the database should land.
     *
     * Defaults to the directory Cairn is already configured to read from, but
     * named after the edition being downloaded — so fetching the City database
     * cannot quietly overwrite the Country one that `geo_database` points at
     * and leave the configuration describing a file that no longer contains
     * what it says.
     */
    private function destination(Config $config, string $edition): string
    {
        $explicit = $this->option('path');

        if (is_string($explicit) && trim($explicit) !== '') {
            $explicit = trim($explicit);

            return str_starts_with($explicit, '/') ? $explicit : base_path($explicit);
        }

        $configured = (new MaxMindGeoResolver($config))->databasePath()
            ?? base_path('storage/app/geoip/GeoLite2-Country.mmdb');

        return dirname($configured).'/'.$edition.'.mmdb';
    }

    /**
     * Say what still needs doing, if anything does.
     *
     * A downloaded database that nothing reads is the most likely outcome of
     * running this command on a fresh installation, and the failure mode is an
     * empty Countries panel with no explanation. Better to be told here.
     */
    private function afterthoughts(Config $config, string $path, string $edition): void
    {
        $notes = [];

        if (! class_exists(Reader::class)) {
            $notes[] = 'Install the reader: <options=bold>composer require geoip2/geoip2</>';
        }

        if ($config->get('cairn.privacy.geo_resolver') !== MaxMindGeoResolver::class) {
            $notes[] = 'Point Cairn at it: set <options=bold>privacy.geo_resolver</> to '
                .'<fg=gray>\\Divoto\\Cairn\\Geo\\MaxMindGeoResolver::class</>';
        }

        $configured = (new MaxMindGeoResolver($config))->databasePath();

        if ($configured !== $path) {
            $notes[] = sprintf(
                'Update the path: <options=bold>CAIRN_GEO_DATABASE=%s</>',
                $this->relative($path),
            );
        }

        if ($edition !== 'GeoLite2-Country' && $config->get('cairn.privacy.geo_precision') === 'country') {
            $notes[] = 'This edition holds more than Cairn will store — <options=bold>privacy.geo_precision</> '
                .'is "country", so anything finer is discarded. The smaller GeoLite2-Country is enough.';
        }

        if ($notes === []) {
            $this->line('  <fg=gray>Country reporting is active. Run cairn:doctor to confirm.</>');
            $this->newLine();

            return;
        }

        $this->line('  <options=bold>Still to do:</>');
        foreach ($notes as $note) {
            $this->line('    • '.$note);
        }
        $this->newLine();
    }

    /**
     * A path relative to the application root, when it is inside it.
     */
    private function relative(string $path): string
    {
        $root = base_path().'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? sprintf('%.1fMB', $bytes / 1_048_576)
            : sprintf('%.0fKB', $bytes / 1024);
    }
}
