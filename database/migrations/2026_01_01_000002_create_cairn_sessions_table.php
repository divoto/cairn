<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Mutable session state: one row per visit, updated as the visit continues.
 *
 * A session is a 30-minute inactivity window over one visitor hash. Because
 * the visitor salt rotates every 24 hours, a session cannot span midnight UTC
 * in any meaningful sense — the identity it is keyed on ceases to exist.
 *
 * Short retention, like `cairn_entries`. The permanent record of what happened
 * lives in `cairn_aggregates`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Tables::driver();
        $composite = Engine::usesCompositeTimeKey($driver);

        $this->schema()->create(Tables::sessions(), function (Blueprint $table) use ($composite): void {
            // Derived as hash(visitor + session_start), so it is already
            // unique and needs no surrogate key.
            $table->binary('id', 16, true);

            $table->binary('visitor', 16, true);

            $table->dateTime('started_at');
            $table->dateTime('last_activity_at');
            $table->dateTime('ended_at')->nullable();

            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->string('entry_url', 512)->nullable();
            $table->string('exit_url', 512)->nullable();

            // page_count <= 1 at the point the session closes.
            $table->boolean('is_bounce')->default(true);

            // Acquisition is recorded once, on the first request of the
            // session, under a last-click model. Cairn does not implement
            // multi-touch attribution and cannot: with a daily-rotating salt
            // there is no cross-day identity to attribute across.
            $table->string('referrer_host')->nullable();
            $table->unsignedTinyInteger('channel')->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('utm_term', 128)->nullable();
            $table->string('utm_content', 128)->nullable();

            $table->char('country', 2)->nullable();
            $table->unsignedSmallInteger('device_type')->nullable();

            $table->string('tenant_id', 64)->nullable();

            // BUILD_PLAN.md gives this table a primary key of `id` alone, but
            // also asks cairn:partition to range-partition it by month.
            // MySQL and MariaDB require every unique key to contain the
            // partitioning column, so those two engines get (id, started_at).
            // Without this the partition command could not run on the very
            // table the plan names.
            if ($composite) {
                $table->primary(['id', 'started_at']);
            } else {
                $table->primary('id');
            }

            $table->index('visitor');
            $table->index('started_at');
            $table->index('last_activity_at');
            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::sessions());
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
