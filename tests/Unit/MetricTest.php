<?php

declare(strict_types=1);

use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\MetricUnit;

/*
|--------------------------------------------------------------------------
| Metric
|--------------------------------------------------------------------------
|
| The additive/derived split is what stops the report builder averaging
| averages. These tests hold that structure in place.
|
*/

/**
 * Every metric, as a single-argument dataset.
 *
 * @return array<string, array{Metric}>
 */
function allMetrics(): array
{
    $dataset = [];

    foreach (Metric::cases() as $metric) {
        $dataset[$metric->value] = [$metric];
    }

    return $dataset;
}

/**
 * Every metric that is a ratio of two others, together with its components.
 *
 * Kept as its own dataset for two reasons: the assertions below never run
 * against an additive metric and pass by doing nothing, and the numerator and
 * denominator arrive already unwrapped, so no assertion has to work around the
 * nullable return of {@see Metric::ratio()}.
 *
 * @return array<string, array{Metric, Metric, Metric}>
 */
function derivedMetrics(): array
{
    $dataset = [];

    foreach (Metric::cases() as $metric) {
        $ratio = $metric->ratio();

        if ($ratio !== null) {
            $dataset[$metric->value] = [$metric, $ratio['numerator'], $ratio['denominator']];
        }
    }

    return $dataset;
}

it('classifies every metric as either additive or a ratio, never both', function (Metric $metric): void {
    expect($metric->isAdditive())->toBe($metric->ratio() === null);
})->with(allMetrics());

it('has derived metrics to test', function (): void {
    expect(derivedMetrics())->not->toBeEmpty();
});

it('builds every derived metric from additive components only', function (
    Metric $metric,
    Metric $numerator,
    Metric $denominator,
): void {
    expect($numerator->isAdditive())->toBeTrue()
        ->and($denominator->isAdditive())->toBeTrue();
})->with(derivedMetrics());

it('never stores a derived metric in the aggregate table', function (Metric $metric): void {
    expect($metric->isStored())->toBeFalse();
})->with(derivedMetrics());

/**
 * Visitors is additive in the sense that the builder sums it, but it lives in
 * the UniqueCounter — a set cardinality cannot be summed out of a rollup row.
 */
it('keeps unique visitors out of the aggregate table', function (): void {
    expect(Metric::Visitors->isAdditive())->toBeTrue()
        ->and(Metric::Visitors->isStored())->toBeFalse();
});

it('computes bounce rate from bounces over sessions', function (): void {
    expect(Metric::BounceRate->ratio())->toBe([
        'numerator' => Metric::Bounces,
        'denominator' => Metric::Sessions,
    ]);
});

it('computes average session duration from total seconds over sessions', function (): void {
    expect(Metric::AvgSessionDuration->ratio())->toBe([
        'numerator' => Metric::SessionSeconds,
        'denominator' => Metric::Sessions,
    ]);
});

it('pairs every averaged beacon metric with its own sample count', function (): void {
    expect(Metric::AvgTimeOnPage->ratio())->toBe([
        'numerator' => Metric::TimeOnPageSeconds,
        'denominator' => Metric::TimeOnPageSamples,
    ])->and(Metric::AvgScrollDepth->ratio())->toBe([
        'numerator' => Metric::ScrollDepthTotal,
        'denominator' => Metric::ScrollDepthSamples,
    ]);
});

it('flags the metrics that only the beacon can measure', function (): void {
    expect(Metric::AvgTimeOnPage->requiresBeacon())->toBeTrue()
        ->and(Metric::AvgScrollDepth->requiresBeacon())->toBeTrue()
        ->and(Metric::Pageviews->requiresBeacon())->toBeFalse()
        ->and(Metric::Sessions->requiresBeacon())->toBeFalse();
});

it('gives a derived metric the same beacon requirement as its components', function (
    Metric $metric,
    Metric $numerator,
    Metric $denominator,
): void {
    expect($metric->requiresBeacon())
        ->toBe($numerator->requiresBeacon() || $denominator->requiresBeacon());
})->with(derivedMetrics());

it('renders rates as percentages and durations as time', function (): void {
    expect(Metric::BounceRate->unit())->toBe(MetricUnit::Percentage)
        ->and(Metric::ConversionRate->unit())->toBe(MetricUnit::Percentage)
        ->and(Metric::AvgSessionDuration->unit())->toBe(MetricUnit::Seconds)
        ->and(Metric::AvgResponseTime->unit())->toBe(MetricUnit::Milliseconds)
        ->and(Metric::ConversionValue->unit())->toBe(MetricUnit::Currency)
        ->and(Metric::Pageviews->unit())->toBe(MetricUnit::Count);
});

it('gives every metric a label', function (Metric $metric): void {
    expect($metric->label())->not->toBe('');
})->with(allMetrics());
