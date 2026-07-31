<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Divoto\Cairn\Recorders\ClientMetrics;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Renders the beacon's script tag.
 *
 * Inlined rather than served from a file: it is well under 2KB, so a separate
 * request would cost more than the script does, and inlining means it cannot
 * break when a deployment forgets to republish assets after an upgrade.
 *
 * Inlining does mean a `script-src` Content Security Policy needs to allow it.
 * See {@see self::cspHash()} — Cairn gives you the hash rather than telling you
 * to add 'unsafe-inline', which would weaken the policy for everything else on
 * the page.
 */
final class Beacon
{
    /**
     * The packaged, minified beacon.
     */
    private const SCRIPT = __DIR__.'/../../resources/dist/cairn.min.js';

    /**
     * The tag to place before `</body>`, or an empty string when the beacon is
     * switched off.
     */
    public static function tag(): string
    {
        if (! self::enabled()) {
            return '';
        }

        $script = self::script();

        if ($script === '') {
            return '';
        }

        return sprintf(
            '<script data-cairn-endpoint="%s">%s</script>',
            htmlspecialchars(self::endpoint(), ENT_QUOTES),
            $script,
        );
    }

    /**
     * The `script-src` hash for a Content Security Policy.
     *
     *     script-src 'self' '<?= Beacon::cspHash() ?>';
     */
    public static function cspHash(): string
    {
        $script = self::script();

        return $script === '' ? '' : "'sha256-".base64_encode(hash('sha256', $script, true))."'";
    }

    /**
     * Where the beacon posts its measurements.
     */
    public static function endpoint(): string
    {
        $path = app(Config::class)->get('cairn.dashboard.path');

        return '/'.trim(is_string($path) && $path !== '' ? $path : 'cairn', '/').'/collect';
    }

    /**
     * Whether the beacon should be emitted at all.
     */
    public static function enabled(): bool
    {
        $config = app(Config::class);

        return $config->get('cairn.enabled') === true
            && $config->get('cairn.recorders.'.ClientMetrics::class.'.enabled') !== false;
    }

    /**
     * The minified beacon source.
     */
    private static function script(): string
    {
        $published = public_path('vendor/cairn/cairn.min.js');

        if (is_file($published)) {
            return trim((string) file_get_contents($published));
        }

        return is_file(self::SCRIPT) ? trim((string) file_get_contents(self::SCRIPT)) : '';
    }
}
