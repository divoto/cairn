<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent record: pre-computed counts, kept forever by default.
 *
 * This is the only table the dashboard reads. It contains counts, not records
 * of people — there is no visitor hash here, and nothing in a row points at an
 * individual — which is why it is not subject to the retention limits that
 * govern the raw tables.
 *
 * Only additive metrics are stored. Bounce rate, average duration and every
 * other ratio is recomputed on read from its stored components, so that a
 * week's figure is that week's numerator over that week's denominator rather
 * than an average of seven daily ratios.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create(Tables::aggregates(), function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Calendar-aligned bucket start as a Unix timestamp. Aligned, not
            // trimmed: a day bucket starts at midnight and a month bucket on
            // the first, so a "last 30 days" window is 30 whole buckets.
            $table->unsignedInteger('bucket');

            $table->string('period', 8);

            // What is being counted — the metric name, or an aggregate family
            // such as a widget's dataset.
            $table->string('type', 64);

            $table->string('aggregate', 16);

            // The dimension tuple this row is broken down by, as JSON. Stored
            // for display; never matched on, because a text comparison across
            // engines with different collations is not something to build an
            // upsert on.
            $table->text('key');

            // The hash of that tuple. This is what the unique index matches,
            // and what makes aggregate-on-write an upsert rather than a
            // read-then-write race.
            $table->binary('key_hash', 16, true);

            $table->decimal('value', 20, 2)->default(0);

            /*
             * NOT NULL with an empty-string default.
             *
             * All four supported engines treat NULLs as distinct inside a
             * unique index, so a nullable column here would let two rows
             * differing only by a NULL tenant both be accepted — and the
             * upsert that relies on this index would insert a duplicate
             * instead of updating it. On a single-tenant installation, where
             * every row has no tenant, that would break every aggregate.
             */
            $table->string('tenant_id', 64)->default('');

            $table->unique(
                ['bucket', 'period', 'type', 'aggregate', 'key_hash', 'tenant_id'],
                'cairn_aggregates_unique'
            );

            // Serves the dashboard's read pattern: a window of buckets for one
            // period and type, scoped to a tenant.
            $table->index(['period', 'type', 'bucket'], 'cairn_aggregates_read');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::aggregates());
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
