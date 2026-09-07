<?php

declare(strict_types=1);

namespace Divoto\Cairn\Identity;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Data\Session;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\RouteNameGrouper;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Groups a visitor's requests into visits.
 *
 * A session is a 30-minute inactivity window over one visitor hash. The
 * identifier is derived rather than random —
 *
 *     substr(sha256(visitor | start instant), 0, 16)
 *
 * — so it inherits the unlinkability of the visitor hash it is built from: two
 * sessions by the same person on different days share nothing, because their
 * visitor hashes share nothing.
 *
 * Everything here runs after the response has been sent. None of it is on the
 * request's critical path.
 */
final readonly class SessionResolver
{
    /**
     * How long a visit may be idle before the next request starts a new one.
     */
    private const INACTIVITY_MINUTES = 30;

    public function __construct(
        private DatabaseManager $database,
    ) {}

    /**
     * Find the visitor's open session, or start one.
     *
     * Returns a session whose `isNew` says which happened, so the caller can
     * record acquisition data — referrer, campaign, entry URL — exactly once
     * per visit rather than overwriting it on every page.
     */
    public function resolve(
        string $visitor,
        CarbonImmutable $at,
        int|string|null $tenantId = null,
    ): Session {
        $open = $this->openSession($visitor, $at, $tenantId);

        if ($open instanceof Session) {
            return $open;
        }

        return new Session(
            id: $this->idFor($visitor, $at),
            visitor: $visitor,
            startedAt: $at,
            lastActivityAt: $at,
            pageCount: 0,
            isNew: true,
            tenantId: $tenantId,
        );
    }

    /**
     * The identifier a visitor's session starting at an instant would have.
     *
     * Deterministic, so a session can be addressed without first reading it —
     * which is what allows the write to be a single upsert.
     */
    public function idFor(string $visitor, CarbonImmutable $startedAt): string
    {
        return substr(
            hash('sha256', $visitor.'|'.$startedAt->getTimestamp(), true),
            0,
            16,
        );
    }

    /**
     * Persist a page's contribution to a session.
     *
     * One statement. `page_count` is incremented in the database rather than
     * read and written back, so two concurrent requests cannot both read 3 and
     * both write 4. `is_bounce` is recomputed from the resulting count.
     *
     * @param  array<string, mixed>  $acquisition  Written only when the session is new.
     */
    public function record(
        Session $session,
        CarbonImmutable $at,
        ?string $url = null,
        array $acquisition = [],
    ): void {
        $connection = $this->connection();
        $table = $connection->table(Tables::sessions());

        if ($session->isNew) {
            // Acquisition is written once, on the first request of the visit,
            // under a last-click model. Rewriting it on every page would
            // attribute the visit to wherever the visitor happened to be when
            // they left.
            $table->insertOrIgnore(array_merge([
                'id' => Binary::bind($connection, $session->id),
                'visitor' => Binary::bind($connection, $session->visitor),
                'started_at' => $session->startedAt->toDateTimeString(),
                'last_activity_at' => $at->toDateTimeString(),
                'page_count' => 1,
                'duration_seconds' => 0,
                'entry_url' => $this->page($url),
                'exit_url' => $this->page($url),
                'is_bounce' => true,
                'tenant_id' => $this->tenantValue($session->tenantId),
            ], $this->acquisitionColumns($acquisition)));

            return;
        }

        $table->where('id', Binary::bind($connection, $session->id))->update([
            'last_activity_at' => $at->toDateTimeString(),
            'page_count' => $connection->raw('page_count + 1'),
            'duration_seconds' => max(0, $at->getTimestamp() - $session->startedAt->getTimestamp()),
            'exit_url' => $this->page($url),
            // A visit stops being a bounce the moment a second page arrives.
            'is_bounce' => false,
        ]);
    }

    /**
     * The landing- or exit-page key for a URL.
     *
     * The entry's own `url` is a raw path, so `/orders/8814/invoice` and
     * `/orders/9921/invoice` would be two landing pages and neither would
     * aggregate into anything. The same id-collapsing the `path` route
     * grouping uses turns both into `/orders/{id}/invoice`.
     *
     * The query string goes too. UTM parameters are dimensions of their own,
     * and keeping them here would split one landing page across every campaign
     * that pointed at it — the opposite of what the panel is for.
     *
     * Rows written before this stay as they are and age out with retention.
     */
    private function page(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = explode('?', $url)[0];

        return mb_substr(RouteNameGrouper::collapse($path), 0, 512);
    }

    /**
     * The acquisition columns to store on a new session.
     *
     * Filtered to the known set rather than merged blindly, so a caller
     * cannot write an arbitrary column through this path.
     *
     * @param  array<string, mixed>  $acquisition
     * @return array<string, mixed>
     */
    private function acquisitionColumns(array $acquisition): array
    {
        $allowed = [
            'referrer_host', 'channel', 'country', 'device_type',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        ];

        return array_intersect_key($acquisition, array_flip($allowed));
    }

    /**
     * Close sessions that have been idle longer than the window.
     *
     * Called from the same maintenance path as rollup and prune. An unclosed
     * session is not wrong — `ended_at` is a convenience, and every metric is
     * computed from `last_activity_at` — but leaving them open forever makes
     * the table harder to read by hand.
     *
     * @return int The number of sessions closed.
     */
    public function closeIdle(CarbonImmutable $before): int
    {
        return $this->connection()
            ->table(Tables::sessions())
            ->whereNull('ended_at')
            ->where('last_activity_at', '<', $before->subMinutes(self::INACTIVITY_MINUTES)->toDateTimeString())
            ->update(['ended_at' => $this->connection()->raw('last_activity_at')]);
    }

    /**
     * How long a visit may be idle, in minutes.
     */
    public function inactivityMinutes(): int
    {
        return self::INACTIVITY_MINUTES;
    }

    /**
     * The visitor's session that is still within the inactivity window.
     */
    private function openSession(
        string $visitor,
        CarbonImmutable $at,
        int|string|null $tenantId,
    ): ?Session {
        $cutoff = $at->subMinutes(self::INACTIVITY_MINUTES);

        $connection = $this->connection();

        $row = $connection
            ->table(Tables::sessions())
            ->where('visitor', Binary::bind($connection, $visitor))
            ->where('tenant_id', $this->tenantValue($tenantId))
            ->where('last_activity_at', '>=', $cutoff->toDateTimeString())
            ->orderByDesc('last_activity_at')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = (array) $row;

        $id = $data['id'] ?? null;
        $startedAt = $data['started_at'] ?? null;
        $lastActivityAt = $data['last_activity_at'] ?? null;

        if (! is_string($startedAt) || ! is_string($lastActivityAt)) {
            return null;
        }

        return new Session(
            id: Binary::read($id),
            visitor: $visitor,
            startedAt: CarbonImmutable::parse($startedAt),
            lastActivityAt: CarbonImmutable::parse($lastActivityAt),
            pageCount: is_numeric($data['page_count'] ?? null) ? (int) $data['page_count'] : 0,
            isNew: false,
            tenantId: $tenantId,
        );
    }

    /**
     * The stored form of a tenant id.
     *
     * Empty string rather than null: `cairn_sessions.tenant_id` is compared
     * against directly, and NULL comparisons would never match.
     */
    private function tenantValue(int|string|null $tenantId): string
    {
        return $tenantId === null ? '' : (string) $tenantId;
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
