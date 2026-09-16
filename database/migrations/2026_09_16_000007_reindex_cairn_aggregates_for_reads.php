<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Give the dashboard's read an index that matches it.
 *
 * Every panel runs the same shape of query: one tenant, one aggregate family,
 * one period, a handful of metric types, and a range of buckets. The original
 * index covered `(period, type, bucket)` and left `tenant_id` and `aggregate`
 * out, so the engine narrowed to a period and a metric and then discarded most
 * of what it had read — on a site with fifteen materialised dimensions, about
 * fourteen rows in fifteen.
 *
 * On the site-wide panels it was worse than that. `aggregate = 'overall'` is a
 * small fraction of the table, and with the selective column missing from the
 * index MySQL costed the index higher than a table scan and took the scan:
 * 748,000 rows read to return 90. That is the shape of a dashboard that is
 * instant on a new installation and slow on a busy one, since the cost is the
 * size of the table rather than the size of the answer.
 *
 * Measured on MySQL 8 against 748,000 aggregate rows — ninety days of fifteen
 * dimensions — one dashboard's reads go from 0.68s to 0.04s.
 *
 * This replaces the old index rather than joining it. The two serve the same
 * query and the new one is a superset of it, and the ordering here is what
 * makes the difference: the four equality columns first, the bucket range
 * last, so the engine walks exactly the rows it returns. Nothing else reads
 * this table by period — the rollup's delete and the pruner's both lead on
 * `bucket`, which the unique key already covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table(Tables::aggregates(), function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'aggregate', 'period', 'type', 'bucket'],
                'cairn_aggregates_lookup'
            );

            $table->dropIndex('cairn_aggregates_read');
        });
    }

    public function down(): void
    {
        $this->schema()->table(Tables::aggregates(), function (Blueprint $table): void {
            $table->index(['period', 'type', 'bucket'], 'cairn_aggregates_read');

            $table->dropIndex('cairn_aggregates_lookup');
        });
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
