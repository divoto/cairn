<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The database presence driver's storage. Unused when `cairn.driver` is
 * `redis`, which uses a sorted set instead.
 *
 * A five-minute sliding window over current activity, written with `upsert`.
 * This is the one Cairn table whose contents are expected to disappear on
 * their own.
 *
 * The record is deliberately thin. A live-visitor list is a small dataset, and
 * a small dataset with a rich record per row is where individuals become
 * identifiable — so it holds a rotating hash and at most a page, and nothing
 * else.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create(Tables::presence(), function (Blueprint $table): void {
            $table->binary('visitor', 16, true);

            $table->string('page')->nullable();

            $table->dateTime('last_seen_at');

            // NOT NULL, and part of the key: the visitor hash is global, so on
            // a multi-tenant installation the same person visiting two tenants
            // must occupy two rows rather than overwriting one — otherwise
            // each tenant's live count would depend on the other's traffic.
            $table->string('tenant_id', 64)->default('');

            $table->primary(['visitor', 'tenant_id']);

            // Serves both the windowed count and the pruning of stale rows.
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::presence());
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
