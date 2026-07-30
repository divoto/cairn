<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Support\MonthlyPartitions;

/*
|--------------------------------------------------------------------------
| Monthly partitions
|--------------------------------------------------------------------------
|
| The statement builder is pure, so it is tested on every engine the suite
| runs against rather than only on MySQL. A builder testable solely on its
| target engine would be tested rarely, and wrongly.
|
*/

it('partitions a table by month on the given column', function (): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        3,
        CarbonImmutable::parse('2026-01-15', 'UTC'),
    );

    expect($sql)->toStartWith('ALTER TABLE cairn_entries PARTITION BY RANGE COLUMNS(occurred_at) (')
        ->toEndWith(')');
});

/**
 * Boundaries are calendar month starts. Adding 30 days repeatedly would drift
 * out of alignment within a year and silently mis-file rows.
 */
it('aligns boundaries to calendar months regardless of the start date', function (): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        3,
        CarbonImmutable::parse('2026-01-15 13:45:12', 'UTC'),
    );

    expect($sql)
        ->toContain("PARTITION p202601 VALUES LESS THAN ('2026-02-01')")
        ->toContain("PARTITION p202602 VALUES LESS THAN ('2026-03-01')")
        ->toContain("PARTITION p202603 VALUES LESS THAN ('2026-04-01')");
});

it('crosses a year boundary correctly', function (): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        3,
        CarbonImmutable::parse('2026-11-01', 'UTC'),
    );

    expect($sql)
        ->toContain("PARTITION p202611 VALUES LESS THAN ('2026-12-01')")
        ->toContain("PARTITION p202612 VALUES LESS THAN ('2027-01-01')")
        ->toContain("PARTITION p202701 VALUES LESS THAN ('2027-02-01')");
});

/**
 * February is where naive month arithmetic breaks. Starting from the 31st of a
 * month must not produce a boundary of "2028-02-31" or skip a month entirely.
 */
it('handles month lengths and leap years', function (): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        4,
        CarbonImmutable::parse('2028-01-31', 'UTC'),
    );

    expect($sql)
        ->toContain("PARTITION p202801 VALUES LESS THAN ('2028-02-01')")
        ->toContain("PARTITION p202802 VALUES LESS THAN ('2028-03-01')")
        ->toContain("PARTITION p202803 VALUES LESS THAN ('2028-04-01')")
        ->toContain("PARTITION p202804 VALUES LESS THAN ('2028-05-01')");
});

/**
 * Without a MAXVALUE catch-all, the first write past the final boundary fails
 * outright — stopping analytics dead on a date nobody was watching for.
 */
it('always leaves a catch-all partition open', function (int $months): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        $months,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($sql)->toContain('PARTITION pmax VALUES LESS THAN (MAXVALUE)');
})->with([[1], [2], [13], [60]]);

it('creates exactly the requested number of monthly partitions', function (int $months): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        $months,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    // Every partition but the catch-all has a date boundary.
    expect(substr_count($sql, 'VALUES LESS THAN (\''))->toBe($months)
        ->and(substr_count($sql, 'VALUES LESS THAN (MAXVALUE)'))->toBe(1);
})->with([[1], [2], [13], [60]]);

it('refuses to build a table with no partitions at all', function (int $months): void {
    $sql = MonthlyPartitions::statement(
        'cairn_entries',
        'occurred_at',
        $months,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect(substr_count($sql, 'VALUES LESS THAN (\''))->toBe(1);
})->with([[0], [-1], [-100]]);

it('names partitions by the month they cover', function (): void {
    expect(MonthlyPartitions::nameFor(CarbonImmutable::parse('2026-01-01', 'UTC')))->toBe('p202601')
        ->and(MonthlyPartitions::nameFor(CarbonImmutable::parse('2026-12-31', 'UTC')))->toBe('p202612');
});

it('partitions sessions on their own time column', function (): void {
    $sql = MonthlyPartitions::statement(
        'cairn_sessions',
        'started_at',
        2,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($sql)->toContain('PARTITION BY RANGE COLUMNS(started_at)');
});
