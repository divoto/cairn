<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * Where Cairn's tables live.
 *
 * Every table name goes through here so that `cairn.table_prefix` is honoured
 * in one place rather than being spelled out in migrations, drivers, commands
 * and tests independently — which is how a prefix ends up half-applied.
 */
final class Tables
{
    /**
     * The unprefixed names of every table Cairn owns.
     *
     * Used by `cairn:forget`, `cairn:prune` and the schema tests, all of which
     * need to enumerate the tables rather than name them one at a time.
     */
    private const NAMES = [
        'entries',
        'sessions',
        'aggregates',
        'visitor_days',
        'presence',
    ];

    /**
     * The configured table prefix, defaulting to `cairn_`.
     */
    public static function prefix(): string
    {
        $prefix = app(Repository::class)->get('cairn.table_prefix');

        return is_string($prefix) ? $prefix : 'cairn_';
    }

    /**
     * The connection Cairn reads and writes on, or null for the default.
     */
    public static function connection(): ?string
    {
        $connection = app(Repository::class)->get('cairn.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * The driver name of Cairn's connection: mysql, mariadb, pgsql or sqlite.
     */
    public static function driver(): string
    {
        return app(DatabaseManager::class)
            ->connection(self::connection())
            ->getDriverName();
    }

    /**
     * Apply the configured prefix to an unprefixed table name.
     */
    public static function name(string $table): string
    {
        return self::prefix().$table;
    }

    public static function entries(): string
    {
        return self::name('entries');
    }

    public static function sessions(): string
    {
        return self::name('sessions');
    }

    public static function aggregates(): string
    {
        return self::name('aggregates');
    }

    public static function visitorDays(): string
    {
        return self::name('visitor_days');
    }

    public static function presence(): string
    {
        return self::name('presence');
    }

    /**
     * Every Cairn table, prefixed.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(self::name(...), self::NAMES);
    }

    /**
     * Tables holding raw, visitor-level data subject to retention limits.
     *
     * Aggregates are excluded: they are counts rather than records of people,
     * and are kept forever unless the deployer sets a retention window.
     *
     * @return list<string>
     */
    public static function raw(): array
    {
        return [self::entries(), self::sessions(), self::visitorDays(), self::presence()];
    }
}
