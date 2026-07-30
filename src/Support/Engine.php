<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

/**
 * What the underlying database engine can and cannot do.
 *
 * Cairn supports four engines with genuinely different capabilities, and the
 * differences are load-bearing rather than cosmetic — a composite primary key
 * is what makes range partitioning possible, and partitioning is what makes
 * pruning a large raw table cheap. Rather than scatter driver checks through
 * the migrations and commands, they are named here.
 */
final class Engine
{
    /**
     * Engines that support MySQL-style `RANGE COLUMNS` partitioning.
     *
     * @var list<string>
     */
    private const PARTITIONABLE = ['mysql', 'mariadb'];

    /**
     * Whether `cairn:partition` can do anything on this engine.
     *
     * PostgreSQL has declarative partitioning, but its syntax and its
     * requirement to create the partitioned table up front are different
     * enough that supporting it means a different code path, not a different
     * string. SQLite has no partitioning at all. On both, `cairn:partition`
     * says so plainly and exits without pretending to have done something.
     */
    public static function supportsPartitioning(string $driver): bool
    {
        return in_array($driver, self::PARTITIONABLE, true);
    }

    /**
     * Whether the raw tables should carry a composite primary key that
     * includes their time column.
     *
     * MySQL and MariaDB require every unique key — including the primary — to
     * contain the partitioning column. Adding `occurred_at` to the primary key
     * later would mean rebuilding a table with hundreds of millions of rows,
     * so the tables are created that way from the start on engines that can
     * eventually be partitioned.
     *
     * Everywhere else a plain auto-incrementing `id` is used, because the
     * composite key would cost index width and buy nothing.
     */
    public static function usesCompositeTimeKey(string $driver): bool
    {
        return self::supportsPartitioning($driver);
    }

    /**
     * Whether this engine treats NULLs as distinct within a unique index.
     *
     * All four do, which is why no Cairn table puts a nullable column in a
     * unique index: two rows differing only by a NULL would both be accepted,
     * and the upsert that relies on that index would insert a duplicate
     * instead of updating. Tables with a unique index therefore store
     * "no tenant" as an empty string, never as NULL.
     */
    public static function treatsNullsAsDistinctInUniqueIndexes(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true);
    }
}
