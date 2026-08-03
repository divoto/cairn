<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The database unique-counter driver's storage. Unused when `cairn.driver` is
 * `redis`, which counts with HyperLogLog instead.
 *
 * One row per (day, dimension, visitor), written with `insertOrIgnore` so that
 * the hundredth pageview of the day costs a rejected insert rather than a read
 * followed by a write.
 *
 * **Counting is per-day and cannot be otherwise.** The visitor salt rotates
 * every 24 hours, so the same person's hash tomorrow is unrelated to today's.
 * A monthly unique count is therefore the sum of daily unique counts, which
 * counts a returning visitor once per day they visited. That inflates
 * long-range visitor numbers relative to a cookie-based tool — deliberately,
 * as the direct consequence of not being able to follow people across days.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create(Tables::visitorDays(), function (Blueprint $table): void {
            $table->date('day');

            // The hash of the dimension tuple being counted under, so that
            // "unique visitors to /pricing" and "unique visitors from GB" are
            // separate sets without this table storing readable dimensions
            // alongside visitor hashes.
            $table->binary('dimension_hash', 16, true);

            $table->binary('visitor', 16, true);

            // NOT NULL for the same reason as on cairn_aggregates: a nullable
            // column inside a unique key would let duplicates through on every
            // supported engine, and this table's entire correctness rests on
            // that key rejecting them.
            $table->string('tenant_id', 64)->default('');

            // The uniqueness constraint is the primary key rather than a
            // secondary unique index: the table has no other identity, and
            // clustering on it is exactly the access pattern used to count.
            $table->primary(['day', 'dimension_hash', 'visitor', 'tenant_id'], 'cairn_visitor_days_primary');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::visitorDays());
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
