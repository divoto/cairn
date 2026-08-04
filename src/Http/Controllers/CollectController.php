<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Controllers;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\ScreenClass;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recording\EntryFactory;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\Tables;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives the optional beacon's measurements.
 *
 * This is the only endpoint in Cairn that accepts data from a browser, which
 * makes it the only place a stranger can try to write to the analytics tables.
 * It is treated accordingly:
 *
 * 1. **The privacy gate runs first.** A visitor who sent DNT, GPC or opted out
 *    is not measured here either.
 * 2. **Every field is validated and clamped.** Nothing is stored as sent.
 * 3. **The payload must match a real pageview.** The visitor hash is derived
 *    from the request itself, never taken from the body, and an entry for that
 *    visitor and URL must already exist within the last few minutes. Without
 *    that, anyone could POST arbitrary numbers for arbitrary pages.
 * 4. **One submission per page view.** A replay is dropped rather than
 *    doubling a page's measurements.
 * 5. **Rate limited per visitor.**
 *
 * The response is always 204, whatever happened. Telling a caller which of the
 * checks rejected them would turn this into an oracle for probing the rest.
 */
final readonly class CollectController
{
    /**
     * How long after a pageview its measurements are still accepted.
     *
     * Long enough for somebody to read a page and close the tab; short enough
     * that a captured payload is not replayable an hour later.
     */
    private const WINDOW_MINUTES = 60;

    /**
     * How many submissions one visitor may make per minute.
     */
    private const RATE_LIMIT = 60;

    public function __construct(
        private PrivacyGate $gate,
        private EntryFactory $entries,
        private DatabaseManager $database,
        private Cache $cache,
        private Config $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->collect($request);
        } catch (Throwable $e) {
            report($e);
        }

        return new JsonResponse(null, 204);
    }

    private function collect(Request $request): void
    {
        if ($this->config->get('cairn.enabled') !== true) {
            return;
        }

        if ($this->config->get('cairn.recorders.'.ClientMetrics::class.'.enabled') === false) {
            return;
        }

        if (! $this->gate->allows($request)) {
            return;
        }

        $payload = $this->validate($request);

        if ($payload === null) {
            return;
        }

        // Derived from the request, never read from the body. A body-supplied
        // identity would let anyone attribute measurements to anyone.
        $visitor = $this->entries->visitorFor($request);

        if (! $this->withinRateLimit($visitor)) {
            return;
        }

        $entryId = $this->recentEntryId($visitor, $payload['url']);

        if ($entryId === null) {
            return;
        }

        if (! $this->firstSubmission($visitor, $entryId)) {
            return;
        }

        $this->apply($entryId, $payload);
    }

    /**
     * Read the payload, rejecting anything that is not exactly what the beacon
     * sends.
     *
     * @return array{url: string, seconds: int, scroll: int, screen: ScreenClass}|null
     */
    private function validate(Request $request): ?array
    {
        $data = $request->json()->all();

        if (! is_array($data)) {
            return null;
        }

        $url = $data['url'] ?? null;

        // A path, not a URL. Anything with a scheme or a host is either a
        // mistake or an attempt to record a page on another site.
        if (! is_string($url) || $url === '' || ! str_starts_with($url, '/') || str_contains($url, '//')) {
            return null;
        }

        $seconds = $this->integer($data['seconds'] ?? null, 1, 43200);

        if ($seconds === null) {
            return null;
        }

        return [
            'url' => mb_substr($url, 0, 512),
            'seconds' => $seconds,
            'scroll' => $this->integer($data['scroll'] ?? null, 0, 100) ?? 0,
            // The exact viewport width never reaches a column: it is bucketed
            // here and the original is discarded.
            'screen' => ScreenClass::fromWidth($this->integer($data['viewport'] ?? null, 0, 20000) ?? 0),
        ];
    }

    /**
     * The id of the most recent unmeasured entry this visitor made for a URL.
     *
     * This is what ties a measurement to something the server already saw.
     * Returns the id rather than a row, so nothing downstream has to reason
     * about an untyped database object.
     */
    private function recentEntryId(string $visitor, string $url): int|string|null
    {
        $connection = $this->connection();

        $id = $connection
            ->table(Tables::entries())
            ->where('visitor', Binary::bind($connection, $visitor))
            ->where('url', $url)
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subMinutes(self::WINDOW_MINUTES)->toDateTimeString())
            ->whereNull('time_on_page')
            ->orderByDesc('occurred_at')
            ->value('id');

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Whether this entry has already been measured.
     */
    private function firstSubmission(string $visitor, int|string $entryId): bool
    {
        $key = 'cairn:collected:'.bin2hex($visitor).':'.$entryId;

        return $this->cache->add($key, 1, self::WINDOW_MINUTES * 60);
    }

    /**
     * Whether this visitor is within their submission budget.
     */
    private function withinRateLimit(string $visitor): bool
    {
        $key = 'cairn:collect-rate:'.bin2hex($visitor).':'.CarbonImmutable::now('UTC')->format('YmdHi');

        $count = $this->cache->get($key);
        $count = is_numeric($count) ? (int) $count : 0;

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        $this->cache->put($key, $count + 1, 120);

        return true;
    }

    /**
     * Write the measurements onto the entry the server already recorded.
     *
     * An update rather than a new row: the beacon is adding what the server
     * could not see about a pageview, not reporting a second pageview.
     *
     * @param  array{url: string, seconds: int, scroll: int, screen: ScreenClass}  $payload
     */
    private function apply(int|string $entryId, array $payload): void
    {
        $this->connection()
            ->table(Tables::entries())
            ->where('id', $entryId)
            ->update([
                'time_on_page' => $payload['seconds'],
                'scroll_depth' => $payload['scroll'],
                'screen_class' => $payload['screen']->value,
            ]);
    }

    /**
     * Read an integer within bounds, or null if it is not one.
     */
    private function integer(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
