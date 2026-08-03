<?php

declare(strict_types=1);

namespace Divoto\Cairn\Maintenance;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Erases everything Cairn holds about one subject.
 *
 * Erasure has to do two things, and the second is the one that gets forgotten:
 * remove the rows, *and* rebuild the aggregates those rows contributed to.
 * Deleting an entry without recomputing leaves the person's activity still
 * counted in every rollup — visible in the totals, just no longer attributable.
 * That is not erasure, and a dashboard whose numbers no longer match its raw
 * data is worse than one that never erased anything.
 *
 * Note what this can and cannot reach. A visitor hash rotates every 24 hours,
 * so erasing by hash removes at most one day's activity — the same person's
 * rows from last week are behind a hash nobody can compute any more, including
 * Cairn. That is a consequence of the design working, not a gap in it, and the
 * command says so rather than implying a completeness it cannot deliver.
 */
final readonly class Eraser
{
    public function __construct(
        private DatabaseManager $database,
        private Storage $storage,
    ) {}

    /**
     * Erase everything recorded for a visitor hash.
     *
     * @param  string  $visitor  The raw 16-byte hash, or its hex form.
     * @return array<string, int> Rows removed, per table.
     */
    public function forgetVisitor(string $visitor): array
    {
        $binary = $this->binary($visitor);
        $windows = $this->windowsFor('visitor', $binary);

        $removed = [
            'entries' => $this->connection()->table(Tables::entries())->where('visitor', $binary)->delete(),
            'sessions' => $this->connection()->table(Tables::sessions())->where('visitor', $binary)->delete(),
            'visitor_days' => $this->connection()->table(Tables::visitorDays())->where('visitor', $binary)->delete(),
            'presence' => $this->connection()->table(Tables::presence())->where('visitor', $binary)->delete(),
        ];

        $removed['aggregates_rebuilt'] = $this->rebuild($windows);

        return $removed;
    }

    /**
     * Erase everything recorded for an authenticated user.
     *
     * Only reaches anything when `privacy.track_user_id` was enabled at the
     * time — otherwise Cairn never stored the association and there is nothing
     * to find, which is the default and the point.
     *
     * @return array<string, int>
     */
    public function forgetUser(int|string $userId): array
    {
        $windows = $this->windowsFor('user_id', $userId);

        $removed = [
            'entries' => $this->connection()->table(Tables::entries())->where('user_id', $userId)->delete(),
        ];

        $removed['aggregates_rebuilt'] = $this->rebuild($windows);

        return $removed;
    }

    /**
     * Everything Cairn holds about a visitor, for a subject access request.
     *
     * @return array<string, mixed>
     */
    public function exportVisitor(string $visitor): array
    {
        $binary = $this->binary($visitor);

        return [
            'subject' => ['type' => 'visitor', 'hash' => bin2hex($binary)],
            'note' => 'A visitor hash is derived from a salt that rotates every 24 hours '
                .'and is never stored. Cairn cannot determine whether activity under a '
                .'different hash belongs to the same person, and neither can anyone else.',
            'entries' => $this->rowsFor(Tables::entries(), 'visitor', $binary),
            'sessions' => $this->rowsFor(Tables::sessions(), 'visitor', $binary),
            'presence' => $this->rowsFor(Tables::presence(), 'visitor', $binary),
        ];
    }

    /**
     * Everything Cairn holds about a user, for a subject access request.
     *
     * @return array<string, mixed>
     */
    public function exportUser(int|string $userId): array
    {
        return [
            'subject' => ['type' => 'user', 'id' => $userId],
            'note' => 'Cairn only records a user ID when privacy.track_user_id is '
                .'explicitly enabled. An empty result means it was not.',
            'entries' => $this->rowsFor(Tables::entries(), 'user_id', $userId),
        ];
    }

    /**
     * The days a subject has activity in, so only those get recomputed.
     *
     * Collected before deletion — afterwards there is nothing left to ask.
     *
     * @return list<CarbonImmutable>
     */
    private function windowsFor(string $column, mixed $value): array
    {
        $days = $this->connection()
            ->table(Tables::entries())
            ->where($column, $value)
            ->distinct()
            ->pluck('occurred_at')
            ->map(static fn (mixed $at): ?string => is_string($at) ? substr($at, 0, 10) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_values(array_map(
            static fn (mixed $day): CarbonImmutable => CarbonImmutable::parse(
                is_scalar($day) ? (string) $day : '',
                'UTC',
            ),
            $days,
        ));
    }

    /**
     * Recompute the aggregates for every window the subject appeared in.
     *
     * Hour, day and month, because a single entry contributes to all three.
     *
     * @param  list<CarbonImmutable>  $days
     * @return int The number of aggregate rows rewritten.
     */
    private function rebuild(array $days): int
    {
        $written = 0;

        foreach ($days as $day) {
            foreach ([Period::Hour, Period::Day, Period::Month] as $period) {
                $written += $this->storage->rollup($day->startOfDay(), $day->endOfDay(), $period);
            }
        }

        return $written;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(string $table, string $column, mixed $value): array
    {
        $rows = $this->connection()
            ->table($table)
            ->where($column, $value)
            ->get()
            ->map(function (object $row): array {
                $data = (array) $row;

                // Binary columns are hex-encoded so the export is valid JSON.
                foreach (['visitor', 'session', 'id'] as $binary) {
                    if (isset($data[$binary]) && is_string($data[$binary])) {
                        $data[$binary] = bin2hex($data[$binary]);
                    }
                }

                return $data;
            })
            ->values()
            ->all();

        return array_values($rows);
    }

    /**
     * Accept a hash as raw bytes or as hex.
     *
     * A person filing a request will have been given the hex form, because
     * that is what the dashboard shows.
     */
    private function binary(string $visitor): string
    {
        if (strlen($visitor) === 32 && ctype_xdigit($visitor)) {
            $decoded = hex2bin($visitor);

            return $decoded === false ? $visitor : $decoded;
        }

        return $visitor;
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
