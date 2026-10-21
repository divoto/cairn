<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

/**
 * The key one row of Broken pages is stored under.
 *
 * A row is a status, a URL and the host that sent the visitor there — one
 * dead link, as someone else is hosting it. The report builder groups by a
 * single value per dimension, so the three are encoded into one string here
 * and decoded again for display, rather than teaching the builder about
 * tuples for the sake of one panel.
 */
final readonly class ErrorPageKey
{
    /**
     * The aggregate key for one status, URL and referring host.
     */
    public static function for(int $status, string $url, ?string $referrerHost): string
    {
        return (string) json_encode([$status, $url, $referrerHost], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Split a key back into its parts.
     *
     * Returns null for anything that is not one, so a corrupted or
     * hand-written aggregate row renders as itself rather than throwing.
     *
     * @return array{status: int, url: string, referrer: string|null}|null
     */
    public static function parse(string $key): ?array
    {
        $decoded = json_decode($key, true);

        if (! is_array($decoded) || count($decoded) !== 3) {
            return null;
        }

        [$status, $url, $referrer] = $decoded;

        if (! is_int($status) || ! is_string($url) || ($referrer !== null && ! is_string($referrer))) {
            return null;
        }

        return ['status' => $status, 'url' => $url, 'referrer' => $referrer];
    }

    /**
     * A key as a reader wants it: "404 · /old-pricing · from example.com".
     */
    public static function label(string $key): string
    {
        $parts = self::parse($key);

        if ($parts === null) {
            return $key;
        }

        $label = $parts['status'].' · '.$parts['url'];

        return $parts['referrer'] === null ? $label : $label.' · from '.$parts['referrer'];
    }
}
