<?php

declare(strict_types=1);

namespace Divoto\Cairn\Exceptions;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use RuntimeException;

/**
 * A report asked for a combination that was never materialised.
 *
 * Cairn throws rather than falling back to scanning `cairn_entries`. A silent
 * fallback would turn a fast dashboard into a full table scan without anybody
 * noticing until the table was large — and by then the raw data it scanned
 * would be past its retention window and the numbers would be wrong as well as
 * slow.
 *
 * The message always names the combination and says what would make it work,
 * because the person reading it is usually building a custom widget and needs
 * to know whether to change the query or change the rollup.
 */
final class UnavailableDimensionException extends RuntimeException
{
    /**
     * A dimension exists as a column but is not rolled up.
     */
    public static function notMaterialised(Dimension $dimension): self
    {
        return new self(sprintf(
            'Cairn does not materialise a rollup for the "%s" dimension, so it cannot be '
            .'grouped or filtered by. It is recorded on raw entries, but the dashboard '
            .'reads aggregates only. High-cardinality dimensions (%s) are excluded '
            .'deliberately: rolling them up writes a row per distinct value per bucket, '
            .'forever.',
            $dimension->value,
            implode(', ', array_map(
                static fn (Dimension $d): string => $d->value,
                array_filter(Dimension::cases(), static fn (Dimension $d): bool => ! $d->isMaterialised()),
            )),
        ));
    }

    /**
     * Two dimensions were combined, and v1 materialises no cross-products.
     */
    public static function combination(Dimension $groupBy, Dimension $filter): self
    {
        return new self(sprintf(
            'Cairn cannot break "%s" down by "%s": v1 materialises single-dimension '
            .'rollups only, never pairs. The cross-product of every dimension against '
            .'every other would multiply the size of cairn_aggregates by orders of '
            .'magnitude. Group by one and filter on nothing, or query raw entries '
            .'directly over an explicitly bounded window.',
            $groupBy->value,
            $filter->value,
        ));
    }

    /**
     * A metric is not available at the requested grouping.
     */
    public static function metric(Metric $metric, ?Dimension $dimension): self
    {
        return new self(sprintf(
            'The "%s" metric is not available%s. Unique visitors are counted per day '
            .'against specific dimensions as traffic arrives — currently site-wide and '
            .'per route — because a set cardinality cannot be summed out of a rollup '
            .'table after the fact.',
            $metric->value,
            $dimension instanceof Dimension ? sprintf(' grouped by "%s"', $dimension->value) : '',
        ));
    }
}
