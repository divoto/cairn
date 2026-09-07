<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\ScreenClass;
use Divoto\Cairn\Widgets\DimensionWidget;
use Divoto\Cairn\Widgets\Filters;
use Illuminate\Support\Collection;

/**
 * How wide visitors' viewports are, in four buckets.
 *
 * Exact dimensions are a strong fingerprinting signal, so the beacon reports a
 * width and the recorder keeps only the bucket. Without the beacon nothing
 * measures this at all, and the panel says so rather than showing a zero.
 */
final class ScreenSizes extends DimensionWidget
{
    public function key(): string
    {
        return 'screen-sizes';
    }

    public function title(): string
    {
        return 'Screen sizes';
    }

    public function description(): string
    {
        return 'Bucketed on arrival; the exact width is never stored.';
    }

    /**
     * Drop the unknown bucket.
     *
     * A pageview recorded before the beacon reported, or with a width the
     * browser would not give, buckets as `Unknown`. That is a fact about
     * measurement rather than about screens, and on a ranked table it reads as
     * a size — often the largest one.
     *
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        return parent::rows($filters)
            ->reject(function (ReportRow $row): bool {
                $value = $row->dimensions[Dimension::ScreenClass->value] ?? null;

                return $value === null
                    || $value === ''
                    || (int) $value === ScreenClass::Unknown->value;
            })
            ->values();
    }

    protected function dimension(): Dimension
    {
        return Dimension::ScreenClass;
    }
}
