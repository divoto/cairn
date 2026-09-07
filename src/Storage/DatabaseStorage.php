<?php

declare(strict_types=1);

namespace Divoto\Cairn\Storage;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\AggregateQuery;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\DimensionSource;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\Buckets;
use Divoto\Cairn\Support\EntryMapper;
use Divoto\Cairn\Support\SubjectKey;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * The only storage driver in v1.
 *
 * Writes raw entries, materialises rollups, reads rollups back, and owns
 * retention for the raw table. Everything here runs after the response has
 * been sent.
 */
final readonly class DatabaseStorage implements Storage
{
    /**
     * How many rows to send in a single insert.
     *
     * `cairn_entries` has 33 columns, so 200 rows is 6,600 placeholders —
     * comfortably inside SQLite's default ceiling of 32,766 and every other
     * engine's limit.
     */
    private const INSERT_CHUNK = 200;

    /**
     * How many rows to delete per statement when pruning without partitions.
     *
     * Deliberately modest: a prune competing with live traffic should take
     * longer rather than hold locks the host application waits on.
     */
    private const DELETE_CHUNK = 1000;

    /**
     * Google's "good" thresholds for the Core Web Vitals.
     *
     * Constants rather than config: they are the published definition of the
     * metric, and a deployment that moved them would report a "good LCP share"
     * that meant something different from everybody else's.
     */
    private const LCP_GOOD_MS = 2500;

    private const INP_GOOD_MS = 200;

    /** 0.10, in the thousandths the column stores. */
    private const CLS_GOOD_MILLI = 100;

    public function __construct(
        private DatabaseManager $database,
    ) {}

    /**
     * @param  Collection<int, Entry>  $entries
     */
    public function store(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $connection = $this->connection();

        // EntryMapper is driver-agnostic by design — the Redis path uses it
        // too — so the binary columns are prepared here rather than there.
        $rows = $entries->map(static fn (Entry $entry): array => Binary::bindRow(
            $connection,
            EntryMapper::toRow($entry),
            ['visitor', 'session'],
        ));

        foreach ($rows->chunk(self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Tables::entries())->insert($chunk->values()->all());
        }
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function aggregate(AggregateQuery $query): Collection
    {
        $wanted = array_values(array_map(
            static fn (Metric $metric): string => $metric->value,
            array_filter($query->metrics, static fn (Metric $metric): bool => $metric->isStored()),
        ));

        if ($wanted === []) {
            return new Collection;
        }

        $rows = $this->connection()
            ->table(Tables::aggregates())
            ->where('period', $query->period->value)
            ->where('tenant_id', $this->tenantValue($query->tenantId))
            ->where('aggregate', $this->aggregateKey($query->groupBy))
            ->where('bucket', '>=', $query->from->getTimestamp())
            ->where('bucket', '<', $query->to->getTimestamp())
            ->whereIn('type', $wanted)
            ->get();

        /** @var Collection<string, Collection<int, object>> $grouped */
        $grouped = $rows->groupBy(fn (mixed $row): string => $this->groupKey((object) $row));

        /** @var Collection<int, ReportRow> */
        return $grouped
            ->map(fn (Collection $group): ReportRow => $this->toReportRow($group))
            ->values();
    }

    /**
     * Recompute rollups for a window, replacing whatever is there.
     *
     * Idempotent by construction: the window's rows are deleted and rebuilt,
     * so running it twice leaves identical output and a partially-written
     * previous run repairs itself.
     *
     * Buckets are iterated in PHP and each is aggregated with its own query,
     * rather than grouping by a date expression in SQL. Every engine spells
     * date truncation differently — and gets daylight-saving boundaries
     * differently wrong — so the calendar arithmetic stays in Carbon, where it
     * is correct and testable, and SQL only ever sees two timestamps.
     *
     * @return int The number of aggregate rows written.
     */
    public function rollup(CarbonInterface $from, CarbonInterface $to, Period $period): int
    {
        $written = 0;

        foreach (Buckets::between($from->toImmutable(), $to->toImmutable(), $period) as $bucket) {
            $written += $this->rollupBucket($bucket, $period);
        }

        return $written;
    }

    /**
     * Delete raw entries older than the given instant.
     *
     * Only ever touches `cairn_entries`. Sessions, presence and visitor-days
     * have their own retention, and aggregates are the permanent record.
     */
    public function prune(CarbonInterface $before): int
    {
        $cutoff = $before->toDateTimeString();
        $deleted = 0;

        do {
            $removed = $this->connection()
                ->table(Tables::entries())
                ->where('occurred_at', '<', $cutoff)
                ->limit(self::DELETE_CHUNK)
                ->delete();

            $deleted += $removed;
        } while ($removed >= self::DELETE_CHUNK);

        return $deleted;
    }

    /**
     * Rebuild every materialised aggregate for one bucket.
     */
    private function rollupBucket(CarbonImmutable $bucket, Period $period): int
    {
        $end = Buckets::next($bucket, $period);

        $tenants = $this->tenantsIn($bucket, $end);
        $written = 0;

        foreach ($tenants as $tenant) {
            $this->connection()
                ->table(Tables::aggregates())
                ->where('period', $period->value)
                ->where('bucket', $bucket->getTimestamp())
                ->where('tenant_id', $tenant)
                ->delete();

            $rows = [];

            // The site-wide totals, stored under an empty dimension tuple.
            $overall = array_merge(
                $this->measure($bucket, $end, $tenant),
                $this->measureSessions($bucket, $end, $tenant),
            );

            foreach ($overall as $metric => $value) {
                $rows[] = $this->aggregateRow($bucket, $period, $tenant, 'overall', [], $metric, $value);
            }

            // One single-dimension breakdown per materialised dimension. The
            // cross-product is deliberately not materialised in v1.
            foreach (Dimension::cases() as $dimension) {
                if (! $dimension->isMaterialised()) {
                    continue;
                }

                $measured = match (true) {
                    $dimension->source() === DimensionSource::Sessions => $this->measureSessionsBy($bucket, $end, $tenant, $dimension),
                    $dimension->isComposite() => $this->measureSubjects($bucket, $end, $tenant),
                    default => $this->measureBy($bucket, $end, $tenant, $dimension),
                };

                foreach ($measured as $value => $metrics) {
                    foreach ($metrics as $metric => $amount) {
                        $rows[] = $this->aggregateRow(
                            $bucket,
                            $period,
                            $tenant,
                            $dimension->value,
                            [$dimension->value => $value],
                            $metric,
                            $amount,
                        );
                    }
                }
            }

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $this->connection()->table(Tables::aggregates())->insert($chunk);
            }

            $written += count($rows);
        }

        return $written;
    }

    /**
     * Session-derived metrics for a bucket.
     *
     * Sessions live in their own table, so they are measured separately rather
     * than derived from entries. Without this, bounce rate and average session
     * duration have no denominator and can never be reported — the components
     * simply would not exist.
     *
     * Sessions are attributed to the bucket they *started* in. A visit that
     * spans midnight belongs to the day it began, which is the only assignment
     * that keeps the daily counts summing to the monthly one.
     *
     * @return array<string, float>
     */
    private function measureSessions(CarbonImmutable $from, CarbonImmutable $to, string $tenant): array
    {
        $row = (array) $this->connection()
            ->table(Tables::sessions())
            ->where('tenant_id', $tenant)
            ->where('started_at', '>=', $from->toDateTimeString())
            ->where('started_at', '<', $to->toDateTimeString())
            ->selectRaw(implode(', ', [
                'count(*) as m_sessions',
                // Tested for truthiness rather than compared against 1:
                // `is_bounce` is a real boolean on PostgreSQL, where
                // `is_bounce = 1` is not a comparison but a type error.
                'sum(case when is_bounce then 1 else 0 end) as m_bounces',
                'sum(coalesce(duration_seconds, 0)) as m_session_seconds',
            ]))
            ->first();

        $out = [];

        foreach ([
            'm_sessions' => Metric::Sessions,
            'm_bounces' => Metric::Bounces,
            'm_session_seconds' => Metric::SessionSeconds,
        ] as $column => $metric) {
            $value = $row[$column] ?? null;
            $amount = is_numeric($value) ? (float) $value : 0.0;

            if ($amount !== 0.0) {
                $out[$metric->value] = $amount;
            }
        }

        return $out;
    }

    /**
     * Measure the subject dimension for a bucket.
     *
     * The one dimension keyed from two columns, so it gets its own small
     * branch rather than bending {@see self::measureBy()} out of shape.
     *
     * A subject only ever reaches an entry through `trackView()`,
     * `trackEvent()` or `trackConversion()`, all of which record an event or a
     * conversion — never a pageview. So the pageview count of an article would
     * always be zero, which is useless, and its event count would silently
     * include its views, which is worse.
     *
     * Views are therefore counted as this dimension's pageviews, and the event
     * count excludes them. "Viewed" is Cairn's own vocabulary rather than
     * something a caller chose, which is what entitles storage to read it:
     * {@see SubjectKey::VIEW_EVENT}.
     *
     * @return array<string, array<string, float>>
     */
    private function measureSubjects(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $tenant,
    ): array {
        $event = "'".EntryType::Event->value."'";
        $conversion = "'".EntryType::Conversion->value."'";
        $viewed = "'".SubjectKey::VIEW_EVENT."'";

        $rows = $this->entriesIn($from, $to, $tenant)
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->groupBy('subject_type', 'subject_id')
            ->selectRaw('subject_type, subject_id, '.implode(', ', [
                "sum(case when type = {$event} and name = {$viewed} then 1 else 0 end) as m_views",
                "sum(case when type = {$event} and name <> {$viewed} then 1 else 0 end) as m_events",
                "sum(case when type = {$conversion} then 1 else 0 end) as m_conversions",
                "sum(case when type = {$conversion} then coalesce(value, 0) else 0 end) as m_conversion_value",
            ]))
            ->get();

        $map = [
            'm_views' => Metric::Pageviews,
            'm_events' => Metric::Events,
            'm_conversions' => Metric::Conversions,
            'm_conversion_value' => Metric::ConversionValue,
        ];

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $type = $data['subject_type'] ?? null;
            $id = $data['subject_id'] ?? null;

            // @codeCoverageIgnoreStart
            // Both are excluded at the SQL level by whereNotNull() above.
            if (! is_string($type)) {
                continue;
            }

            if (! is_string($id) && ! is_int($id)) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            $measurements = [];

            foreach ($map as $select => $metric) {
                $amount = is_numeric($data[$select] ?? null) ? (float) $data[$select] : 0.0;

                if ($amount !== 0.0) {
                    $measurements[$metric->value] = $amount;
                }
            }

            if ($measurements !== []) {
                $out[SubjectKey::for($type, $id)] = $measurements;
            }
        }

        return $out;
    }

    /**
     * Measure one session-sourced dimension for a bucket.
     *
     * The sibling of {@see self::measureBy()} for landing and exit pages,
     * which are columns on `cairn_sessions` rather than on `cairn_entries`.
     * Grouping the session table by one of them gives what the entry table
     * cannot: a bounce rate and an average duration for a single page.
     *
     * Sessions are attributed to the bucket they *started* in, exactly as the
     * site-wide figure is. Any other assignment stops the daily counts summing
     * to the monthly one.
     *
     * `page_count` is summed as pageviews. That is a real measurement rather
     * than a borrowed one: the number of pages in the visits that began on
     * this landing page.
     *
     * @return array<string, array<string, float>>
     */
    private function measureSessionsBy(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $tenant,
        Dimension $dimension,
    ): array {
        $column = $dimension->column();

        $rows = $this->connection()
            ->table(Tables::sessions())
            ->where('tenant_id', $tenant)
            ->where('started_at', '>=', $from->toDateTimeString())
            ->where('started_at', '<', $to->toDateTimeString())
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw($column.', '.implode(', ', [
                'count(*) as m_sessions',
                // Truthiness rather than `= 1`: is_bounce is a real boolean on
                // PostgreSQL, where comparing it to an integer is a type error.
                'sum(case when is_bounce then 1 else 0 end) as m_bounces',
                'sum(coalesce(duration_seconds, 0)) as m_session_seconds',
                'sum(coalesce(page_count, 0)) as m_pageviews',
            ]))
            ->get();

        $map = [
            'm_sessions' => Metric::Sessions,
            'm_bounces' => Metric::Bounces,
            'm_session_seconds' => Metric::SessionSeconds,
            'm_pageviews' => Metric::Pageviews,
        ];

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $value = $data[$column] ?? null;

            // @codeCoverageIgnoreStart
            // whereNotNull() above already excludes this at the SQL level.
            if ($value === null) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            $measurements = [];

            foreach ($map as $select => $metric) {
                $amount = is_numeric($data[$select] ?? null) ? (float) $data[$select] : 0.0;

                if ($amount !== 0.0) {
                    $measurements[$metric->value] = $amount;
                }
            }

            if ($measurements !== []) {
                $out[(string) (is_scalar($value) ? $value : '')] = $measurements;
            }
        }

        return $out;
    }

    /**
     * Measure the entry-derived additive metrics for a bucket.
     *
     * @return array<string, float>
     */
    private function measure(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $tenant,
    ): array {
        $row = (array) $this->entriesIn($from, $to, $tenant)
            ->selectRaw($this->measurements())
            ->first();

        return $this->readMeasurements($row);
    }

    /**
     * Measure the additive metrics for a bucket, broken down by a dimension.
     *
     * @return array<string, array<string, float>>
     */
    private function measureBy(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $tenant,
        Dimension $dimension,
    ): array {
        $column = $dimension->column();

        $rows = $this->entriesIn($from, $to, $tenant)
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw($column.', '.$this->measurements())
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $value = $data[$column] ?? null;

            // @codeCoverageIgnoreStart
            // whereNotNull() above already excludes this at the SQL level.
            if ($value === null) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            $measurements = $this->readMeasurements($data);

            if ($measurements !== []) {
                $out[(string) (is_scalar($value) ? $value : '')] = $measurements;
            }
        }

        return $out;
    }

    /**
     * The SQL fragment computing every stored metric from raw entries.
     *
     * Only additive quantities are selected. Bounce rate, average duration and
     * every other ratio is recomputed on read from these components, so a
     * week's figure is that week's numerator over that week's denominator
     * rather than an average of seven daily ratios.
     */
    private function measurements(): string
    {
        $pageview = "'".EntryType::Pageview->value."'";
        $event = "'".EntryType::Event->value."'";
        $conversion = "'".EntryType::Conversion->value."'";

        return implode(', ', [
            "sum(case when type = {$pageview} then 1 else 0 end) as m_pageviews",
            "sum(case when type = {$event} then 1 else 0 end) as m_events",
            "sum(case when type = {$conversion} then 1 else 0 end) as m_conversions",
            "sum(case when type = {$conversion} then coalesce(value, 0) else 0 end) as m_conversion_value",
            'sum(coalesce(duration_ms, 0)) as m_response_ms',
            'sum(case when duration_ms is null then 0 else 1 end) as m_response_samples',
            'sum(coalesce(time_on_page, 0)) as m_time_on_page',
            'sum(case when time_on_page is null then 0 else 1 end) as m_time_samples',
            'sum(coalesce(scroll_depth, 0)) as m_scroll_total',
            'sum(case when scroll_depth is null then 0 else 1 end) as m_scroll_samples',

            // Core Web Vitals. Each is a sum, a sample count and a count of
            // the samples that met Google's threshold, so the share of good
            // page views can be recomputed at whatever level it is displayed
            // at rather than averaged from per-bucket shares.
            //
            // These ride the entry measurement, so every materialised
            // dimension gets them without a second pass — vitals per route
            // fall out of the same query as vitals per country.
            'sum(coalesce(lcp_ms, 0)) as m_lcp_ms',
            'sum(case when lcp_ms is null then 0 else 1 end) as m_lcp_samples',
            'sum(case when lcp_ms is not null and lcp_ms <= '.self::LCP_GOOD_MS.' then 1 else 0 end) as m_lcp_good',
            'sum(coalesce(inp_ms, 0)) as m_inp_ms',
            'sum(case when inp_ms is null then 0 else 1 end) as m_inp_samples',
            'sum(case when inp_ms is not null and inp_ms <= '.self::INP_GOOD_MS.' then 1 else 0 end) as m_inp_good',
            'sum(coalesce(cls_milli, 0)) as m_cls_milli',
            'sum(case when cls_milli is null then 0 else 1 end) as m_cls_samples',
            'sum(case when cls_milli is not null and cls_milli <= '.self::CLS_GOOD_MILLI.' then 1 else 0 end) as m_cls_good',
        ]);
    }

    /**
     * Turn a measurement row into metric values, dropping the zeroes.
     *
     * A zero is not stored: an aggregate table full of rows recording that
     * nothing happened is a table that grows with the dimension space rather
     * than with the traffic.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, float>
     */
    private function readMeasurements(array $row): array
    {
        $map = [
            'm_pageviews' => Metric::Pageviews,
            'm_events' => Metric::Events,
            'm_conversions' => Metric::Conversions,
            'm_conversion_value' => Metric::ConversionValue,
            'm_response_ms' => Metric::ResponseMilliseconds,
            'm_response_samples' => Metric::ResponseSamples,
            'm_time_on_page' => Metric::TimeOnPageSeconds,
            'm_time_samples' => Metric::TimeOnPageSamples,
            'm_scroll_total' => Metric::ScrollDepthTotal,
            'm_scroll_samples' => Metric::ScrollDepthSamples,
            'm_lcp_ms' => Metric::LcpMilliseconds,
            'm_lcp_samples' => Metric::LcpSamples,
            'm_lcp_good' => Metric::LcpGood,
            'm_inp_ms' => Metric::InpMilliseconds,
            'm_inp_samples' => Metric::InpSamples,
            'm_inp_good' => Metric::InpGood,
            'm_cls_milli' => Metric::ClsMilli,
            'm_cls_samples' => Metric::ClsSamples,
            'm_cls_good' => Metric::ClsGood,
        ];

        $out = [];

        foreach ($map as $column => $metric) {
            $value = $row[$column] ?? null;
            $amount = is_numeric($value) ? (float) $value : 0.0;

            if ($amount !== 0.0) {
                $out[$metric->value] = $amount;
            }
        }

        return $out;
    }

    /**
     * A query over the entries in a bucket for one tenant.
     */
    private function entriesIn(CarbonImmutable $from, CarbonImmutable $to, string $tenant): Builder
    {
        return $this->connection()
            ->table(Tables::entries())
            ->where('tenant_id', $tenant)
            ->where('occurred_at', '>=', $from->toDateTimeString())
            ->where('occurred_at', '<', $to->toDateTimeString());
    }

    /**
     * Which tenants have entries in a bucket.
     *
     * A single-tenant installation returns exactly one empty string, so the
     * loop below is the same code in both cases.
     *
     * @return list<string>
     */
    private function tenantsIn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tenants = $this->connection()
            ->table(Tables::entries())
            ->where('occurred_at', '>=', $from->toDateTimeString())
            ->where('occurred_at', '<', $to->toDateTimeString())
            ->distinct()
            ->pluck('tenant_id')
            ->merge(
                // A bucket can hold a session that started in it without an
                // entry of its own, when a visit spans a bucket boundary.
                $this->connection()
                    ->table(Tables::sessions())
                    ->where('started_at', '>=', $from->toDateTimeString())
                    ->where('started_at', '<', $to->toDateTimeString())
                    ->distinct()
                    ->pluck('tenant_id')
            )
            ->map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '')
            ->all();

        return $tenants === [] ? [''] : array_values(array_unique($tenants));
    }

    /**
     * Build one aggregate row.
     *
     * @param  array<string, string|int|null>  $key
     * @return array<string, mixed>
     */
    private function aggregateRow(
        CarbonImmutable $bucket,
        Period $period,
        string $tenant,
        string $aggregate,
        array $key,
        string $metric,
        float $value,
    ): array {
        $encoded = (string) json_encode($key);

        return [
            'bucket' => $bucket->getTimestamp(),
            'period' => $period->value,
            'type' => $metric,
            'aggregate' => substr($aggregate, 0, 16),
            'key' => $encoded,
            'key_hash' => Binary::bind($this->connection(), substr(hash('sha256', $encoded, true), 0, 16)),
            'value' => $value,
            'tenant_id' => $tenant,
        ];
    }

    /**
     * The `aggregate` column value for a grouping.
     *
     * @param  list<Dimension>  $groupBy
     */
    private function aggregateKey(array $groupBy): string
    {
        if ($groupBy === []) {
            return 'overall';
        }

        return substr($groupBy[0]->value, 0, 16);
    }

    /**
     * The dimension tuple and bucket a row belongs to, used to collapse one
     * row per metric back into one row per combination.
     */
    private function groupKey(object $row): string
    {
        $data = (array) $row;

        $key = $data['key'] ?? null;
        $bucket = $data['bucket'] ?? null;

        return (is_string($key) ? $key : '[]')
            .'|'
            .(is_scalar($bucket) ? (string) $bucket : '');
    }

    /**
     * @param  Collection<int, object>  $group
     */
    private function toReportRow(Collection $group): ReportRow
    {
        $metrics = [];
        $dimensions = [];

        foreach ($group as $row) {
            $data = (array) $row;

            $type = $data['type'] ?? null;
            $value = $data['value'] ?? 0;

            if (is_string($type)) {
                $metrics[$type] = (float) (is_numeric($value) ? $value : 0);
            }

            if ($dimensions === [] && isset($data['key']) && is_string($data['key'])) {
                $decoded = json_decode($data['key'], true);

                if (is_array($decoded)) {
                    /** @var array<string, string|int|null> $decoded */
                    $dimensions = $decoded;
                }
            }
        }

        return new ReportRow(dimensions: $dimensions, metrics: $metrics);
    }

    private function tenantValue(int|string|null $tenantId): string
    {
        return $tenantId === null ? '' : (string) $tenantId;
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
