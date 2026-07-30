<?php

declare(strict_types=1);

namespace Divoto\Cairn\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Derives the daily-rotating visitor hash.
 *
 * This is the mechanism the whole package rests on. A visitor is identified by
 *
 *     HMAC-SHA256(ip | user agent | domain, salt)
 *
 * truncated to 16 bytes, where the salt is 32 random bytes that exist only in
 * the cache and are replaced every 24 hours.
 *
 * The consequences are deliberate and worth stating plainly:
 *
 * - **The same person is a different visitor tomorrow.** Nothing links today's
 *   hash to yesterday's, because the key that produced it no longer exists.
 *   This is why Cairn cannot report returning visitors, and why unique counts
 *   over a range are the sum of daily uniques.
 * - **The hash cannot be reversed into an IP address**, and cannot be
 *   recomputed later even with the IP, because the salt is gone.
 * - **Two Cairn installations cannot correlate visitors**, because the domain
 *   is mixed into the input and the salts are independent.
 *
 * The IP address arrives here and leaves nowhere. It is a parameter, it is
 * used once, and it is never assigned to a property, logged, or returned.
 */
final readonly class VisitorHasher
{
    /**
     * Bytes of HMAC output kept. 16 bytes is 128 bits of collision space —
     * ample for a day's traffic, and it halves the width of every index the
     * hash appears in.
     */
    private const HASH_BYTES = 16;

    /**
     * Bytes of randomness in a salt.
     */
    private const SALT_BYTES = 32;

    /**
     * The longest a salt may live, in hours.
     *
     * CLAUDE.md fixes rotation at 24 hours in the default privacy mode. A
     * deployer may rotate *more* often — that is strictly more private — but
     * never less, because a longer window is exactly the linkability this
     * package exists to remove.
     */
    private const MAX_ROTATION_HOURS = 24;

    public function __construct(
        private Cache $cache,
        private Config $config,
    ) {}

    /**
     * The visitor hash for a request, as raw bytes.
     *
     * @param  string  $ip  Discarded immediately; never stored or logged.
     * @param  string|null  $userAgent  Discarded immediately; never stored.
     */
    public function hash(string $ip, ?string $userAgent, ?CarbonImmutable $at = null): string
    {
        return $this->hashWithSalt($ip, $userAgent, $this->salt($at));
    }

    /**
     * The visitor hash the previous rotation window would have produced.
     *
     * **Only for closing sessions that were open when the salt rotated.** It
     * must never be used to record a new entry: doing so would attribute
     * today's activity to yesterday's identity, which is precisely the
     * cross-day linkage that cannot be allowed to exist.
     *
     * Returns null when the previous window's salt has already expired, which
     * is the normal case outside the first hour of a new window.
     */
    public function previousHash(string $ip, ?string $userAgent, ?CarbonImmutable $at = null): ?string
    {
        $previous = $this->existingSalt($this->previousWindow($at ?? $this->now()));

        return $previous === null ? null : $this->hashWithSalt($ip, $userAgent, $previous);
    }

    /**
     * The current salt, generating it if this is its first use.
     *
     * Stored in the cache under a key derived from the rotation window, with a
     * TTL of twice the window so a session spanning a rotation can still be
     * closed. It is never written to a Cairn table, a config file or a log.
     *
     * Note the salt inherits the durability of whatever cache store the
     * application uses: a file or database store puts it on disk, which
     * weakens the guarantee that it cannot be recovered after rotation.
     * `cairn:doctor` reports that condition.
     */
    public function salt(?CarbonImmutable $at = null): string
    {
        $window = $this->windowKey($at ?? $this->now());

        $existing = $this->existingSalt($window);

        if ($existing !== null) {
            return $existing;
        }

        $salt = random_bytes(self::SALT_BYTES);

        // add() rather than put(): two concurrent requests must agree on one
        // salt, or the same visitor would produce two hashes in the same
        // window and every unique count would be wrong.
        $this->cache->add($this->cacheKey($window), base64_encode($salt), $this->ttlSeconds());

        return $this->existingSalt($window) ?? $salt;
    }

    /**
     * The cache key a window's salt is stored under.
     */
    public function cacheKey(string $window): string
    {
        return 'cairn:salt:'.$window;
    }

    /**
     * How often the salt rotates, in hours.
     *
     * Clamped to at most 24: configuration may tighten this but never loosen
     * it.
     */
    public function rotationHours(): int
    {
        $configured = $this->config->get('cairn.privacy.salt_rotation_hours');
        $hours = is_int($configured) ? $configured : self::MAX_ROTATION_HOURS;

        return max(1, min($hours, self::MAX_ROTATION_HOURS));
    }

    /**
     * The identifier of the rotation window an instant falls in.
     *
     * A 24-hour window is named by its UTC date, so rotation happens at
     * midnight UTC. Shorter windows append their ordinal within the day.
     */
    public function windowKey(CarbonImmutable $at): string
    {
        $at = $at->setTimezone('UTC');
        $hours = $this->rotationHours();

        if ($hours === self::MAX_ROTATION_HOURS) {
            return $at->format('Y-m-d');
        }

        return $at->format('Y-m-d').'-'.intdiv($at->hour, $hours);
    }

    /**
     * Compute the hash from an already-resolved salt.
     */
    private function hashWithSalt(string $ip, ?string $userAgent, string $salt): string
    {
        $material = $ip.'|'.($userAgent ?? '').'|'.$this->domain();

        return substr(hash_hmac('sha256', $material, $salt, true), 0, self::HASH_BYTES);
    }

    /**
     * Read a window's salt without creating one.
     */
    private function existingSalt(string $window): ?string
    {
        $stored = $this->cache->get($this->cacheKey($window));

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = base64_decode($stored, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The window immediately before the one an instant falls in.
     */
    private function previousWindow(CarbonImmutable $at): string
    {
        return $this->windowKey($at->subHours($this->rotationHours()));
    }

    /**
     * How long a salt is kept: twice the rotation window.
     */
    private function ttlSeconds(): int
    {
        return $this->rotationHours() * 2 * 3600;
    }

    /**
     * What the hash is scoped to, so two installations cannot correlate.
     */
    private function domain(): string
    {
        $domain = $this->config->get('cairn.domain');

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        $url = $this->config->get('app.url');

        return is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
