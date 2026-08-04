<?php

declare(strict_types=1);

namespace Divoto\Cairn\Presence;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Tracks current activity in a table, one row per visitor per tenant.
 *
 * A five-minute sliding window. Rows are upserted on every request and swept
 * on the same lottery that drives rollup and prune, so a visitor who leaves
 * disappears without anything having to notice they left.
 */
final readonly class DatabasePresence implements Presence
{
    /**
     * How long a visitor counts as present after their last request.
     */
    private const WINDOW_MINUTES = 5;

    public function __construct(
        private DatabaseManager $database,
    ) {}

    public function touch(string $visitor, ?string $page = null): void
    {
        try {
            $connection = $this->connection();

            $connection->table(Tables::presence())->upsert(
                [[
                    'visitor' => Binary::bind($connection, $visitor),
                    'page' => $page,
                    'last_seen_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
                    'tenant_id' => '',
                ]],
                ['visitor', 'tenant_id'],
                ['page', 'last_seen_at'],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function count(): int
    {
        try {
            return $this->within()->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * @return Collection<int, array{visitor: string, page: string|null, last_seen_at: string}>
     */
    public function recent(int $limit = 50): Collection
    {
        try {
            /** @var Collection<int, array{visitor: string, page: string|null, last_seen_at: string}> */
            return $this->within()
                ->orderByDesc('last_seen_at')
                ->limit(max(1, $limit))
                ->get()
                ->map(function (object $row): array {
                    $data = (array) $row;

                    return [
                        // Hex, not raw bytes: this crosses into a view, and a
                        // binary string in HTML is a rendering accident
                        // waiting to happen.
                        'visitor' => bin2hex(Binary::read($data['visitor'] ?? '')),
                        'page' => is_string($data['page'] ?? null) ? $data['page'] : null,
                        'last_seen_at' => is_string($data['last_seen_at'] ?? null) ? $data['last_seen_at'] : '',
                    ];
                })
                ->values();
        } catch (Throwable $e) {
            report($e);

            return new Collection;
        }
    }

    /**
     * Remove rows that have fallen out of the window.
     *
     * @return int The number of rows removed.
     */
    public function sweep(): int
    {
        try {
            return $this->connection()
                ->table(Tables::presence())
                ->where('last_seen_at', '<', $this->cutoff())
                ->delete();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * How long a visitor counts as present, in minutes.
     */
    public function windowMinutes(): int
    {
        return self::WINDOW_MINUTES;
    }

    private function within(): Builder
    {
        return $this->connection()
            ->table(Tables::presence())
            ->where('last_seen_at', '>=', $this->cutoff());
    }

    private function cutoff(): string
    {
        return CarbonImmutable::now('UTC')->subMinutes(self::WINDOW_MINUTES)->toDateTimeString();
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
