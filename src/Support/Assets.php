<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

/**
 * Reads the dashboard's stylesheet.
 *
 * Inlined into the page rather than served as a file. It is around 8KB, which
 * is smaller than the request that would fetch it, and inlining means the
 * dashboard works before `vendor:publish` has been run and cannot break when a
 * deployment forgets to republish after an upgrade.
 *
 * A published copy takes precedence, so a deployer who wants to restyle the
 * dashboard edits one file and their version wins.
 */
final class Assets
{
    /**
     * The packaged stylesheet.
     */
    private const PACKAGED = __DIR__.'/../../resources/dist/cairn.css';

    public static function css(): string
    {
        $published = public_path('vendor/cairn/cairn.css');

        if (is_file($published)) {
            return (string) file_get_contents($published);
        }

        return is_file(self::PACKAGED) ? (string) file_get_contents(self::PACKAGED) : '';
    }
}
