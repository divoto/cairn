<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The raw event table: one row per pageview, event or conversion.
 *
 * Short retention by design. This table is written on every request and read
 * almost never — the dashboard reads `cairn_aggregates` exclusively, and raw
 * drill-down is a separate, explicitly time-bounded surface.
 *
 * **There is no IP address column here, and there never will be.** An IP
 * exists in memory only long enough to derive the visitor hash and perform a
 * geo lookup. A test asserts that no column in any Cairn table is named or
 * typed to hold one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Tables::driver();
        $composite = Engine::usesCompositeTimeKey($driver);

        $this->schema()->create(Tables::entries(), function (Blueprint $table) use ($composite): void {
            // On MySQL and MariaDB the primary key must contain the eventual
            // partitioning column, so it is (id, occurred_at) from the start —
            // adding occurred_at later would mean rebuilding a very large
            // table. The MySQL grammar omits the inline "primary key" when an
            // explicit primary command is present, leaving just auto_increment.
            if ($composite) {
                $table->unsignedBigInteger('id')->autoIncrement();
            } else {
                $table->bigIncrements('id');
            }

            $table->dateTime('occurred_at');

            $table->string('type', 32);
            $table->string('name', 128)->nullable();

            // Raw 16-byte hashes, not hex. Rendered as binary(16) on
            // MySQL/MariaDB, bytea on PostgreSQL and blob on SQLite.
            $table->binary('visitor', 16, true);
            $table->binary('session', 16, true)->nullable();

            $table->string('route')->nullable();

            // Path only. The query string is stripped except for UTM keys.
            $table->string('url', 512)->nullable();

            // Host only — never a full referring URL, which can carry a search
            // query, a session token, or the title of a private document.
            $table->string('referrer_host')->nullable();

            $table->unsignedTinyInteger('channel')->nullable();

            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('utm_term', 128)->nullable();
            $table->string('utm_content', 128)->nullable();

            $table->char('country', 2)->nullable();

            // Only ever written when privacy.geo_precision permits. Anything
            // finer than the configured precision is discarded before an entry
            // is built, so these stay null on a default installation.
            $table->string('region', 64)->nullable();
            $table->string('city', 64)->nullable();

            // Lookup enums. Their integer values are permanent — renumbering a
            // case rewrites the meaning of every historical row.
            $table->unsignedSmallInteger('device_type')->nullable();
            $table->unsignedSmallInteger('browser')->nullable();
            $table->unsignedSmallInteger('os')->nullable();

            // A coarse viewport bucket from the optional beacon. The exact
            // width is a fingerprinting signal and is never stored.
            $table->unsignedTinyInteger('screen_class')->nullable();

            $table->string('language', 8)->nullable();

            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Beacon-only measurements. Null when the beacon is disabled,
            // which is why widgets built on them show an empty state rather
            // than reporting a misleading zero.
            $table->unsignedInteger('time_on_page')->nullable();
            $table->unsignedTinyInteger('scroll_depth')->nullable();

            $table->decimal('value', 20, 2)->nullable();

            $table->json('properties')->nullable();

            // A string key rather than a bigint so that models keyed by ULID,
            // UUID or a natural key work without the deployer editing this
            // migration. The column is nullable and rarely populated.
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();

            /*
             * PERSONAL DATA. Only ever written when privacy.track_user_id is
             * explicitly enabled, which is off by default and documented in
             * config/cairn.php as changing the deployer's obligations.
             */
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('tenant_id', 64)->nullable();

            if ($composite) {
                $table->primary(['id', 'occurred_at']);
            }

            // Deliberately few indexes: this table is written on every request
            // and read only by rollup, prune, forget and time-bounded raw
            // drill-down. Every additional index is paid for on every write.
            $table->index('occurred_at');
            $table->index(['visitor', 'occurred_at']);
            $table->index('session');
            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::entries());
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
