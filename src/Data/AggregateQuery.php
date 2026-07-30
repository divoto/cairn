<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;

/**
 * A request for aggregated numbers, as understood by a Storage driver.
 *
 * This is the narrow contract between the report builder and storage. The
 * builder speaks a fluent, forgiving API; storage receives this, which is
 * already resolved, validated and tenant-scoped.
 *
 * Only additive metrics appear in `$metrics`. Derived metrics are decomposed
 * into their stored components by the builder before a query is constructed,
 * and recombined afterwards — storage never divides.
 */
final readonly class AggregateQuery
{
    /**
     * @param  list<Metric>  $metrics  Additive metrics only.
     * @param  list<Dimension>  $groupBy  Dimensions to break results down by.
     * @param  array<string, list<string>>  $filters  Dimension value => accepted values.
     * @param  Metric|null  $orderBy  Metric to sort by; null preserves storage order.
     * @param  int|null  $limit  Row cap; null returns everything matching.
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public Period $period,
        public array $metrics,
        public array $groupBy = [],
        public array $filters = [],
        public ?Metric $orderBy = null,
        public bool $descending = true,
        public ?int $limit = null,
        public int|string|null $tenantId = null,
    ) {}

    /**
     * Whether this query breaks results down by any dimension.
     *
     * A query with no grouping asks for site-wide totals, which are stored
     * under the "overall" aggregate key.
     */
    public function isOverall(): bool
    {
        return $this->groupBy === [];
    }

    /**
     * Return a copy scoped to a tenant.
     *
     * Applied by the report builder from the TenantResolver, never by a
     * caller — tenant isolation that depends on every call site remembering to
     * ask for it is not isolation.
     */
    public function forTenant(int|string|null $tenantId): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            period: $this->period,
            metrics: $this->metrics,
            groupBy: $this->groupBy,
            filters: $this->filters,
            orderBy: $this->orderBy,
            descending: $this->descending,
            limit: $this->limit,
            tenantId: $tenantId,
        );
    }
}
