<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;

/**
 * Moves raw hashes in and out of Cairn's binary columns.
 *
 * Visitor, session and dimension hashes are stored as raw 16-byte strings
 * rather than as 32-character hex — half the width, on the tables written most
 * often. The cost is that neither direction of the round trip is uniform
 * across the four supported engines, so both are spelled out here rather than
 * assumed to work.
 *
 * **Writing.** Laravel binds every string parameter as `PDO::PARAM_STR`; only
 * a resource is sent as `PDO::PARAM_LOB`. PostgreSQL validates text parameters
 * against the database encoding, and a raw hash is almost never valid UTF-8,
 * so it rejects the statement outright:
 *
 *     SQLSTATE[22021]: invalid byte sequence for encoding "UTF8": 0xc3 0x66
 *
 * MySQL, MariaDB and SQLite accept the identical parameter without complaint,
 * which is why this survived to a release: three of the four engines hide it.
 * On PostgreSQL the value is therefore emitted as a `'\x…'::bytea` literal —
 * Laravel's own {@see Connection::escape()} with its binary flag set — instead
 * of being bound. The literal is safe to inline because `bin2hex()` output is
 * closed over `[0-9a-f]`: there is nothing in it left to escape.
 *
 * A stream would also reach `PDO::PARAM_LOB`, and was the other candidate. It
 * is rejected because a stream can only be read once, and Laravel retries a
 * statement after a lost connection — the retry would silently bind an empty
 * value rather than fail.
 *
 * **Reading.** PostgreSQL hands `bytea` back as a stream resource while the
 * other three return a string, so every read goes through {@see read()}.
 */
final class Binary
{
    /**
     * The value to hand the query builder for a binary column.
     *
     * Returns the string unchanged on engines that bind it correctly, so the
     * three that already worked keep using a bound parameter.
     */
    public static function bind(Connection $connection, ?string $value): string|ExpressionContract|null
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! Engine::rejectsBinaryParameters($connection->getDriverName())) {
            return $value;
        }

        return new Expression($connection->escape($value, true));
    }

    /**
     * The same, for the named columns of a row about to be inserted.
     *
     * Columns absent from the row are left absent rather than added as null,
     * so a partial row stays partial.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    public static function bindRow(Connection $connection, array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if ($value === null || is_string($value)) {
                $row[$column] = self::bind($connection, $value);
            }
        }

        return $row;
    }

    /**
     * Read a binary column back as a PHP string.
     *
     * A test that assumed either shape would pass on three engines and fail on
     * the fourth, so nothing reads one of these columns directly.
     */
    public static function read(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        return is_string($value) ? $value : '';
    }
}
