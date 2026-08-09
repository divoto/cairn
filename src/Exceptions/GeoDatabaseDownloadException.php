<?php

declare(strict_types=1);

namespace Divoto\Cairn\Exceptions;

use RuntimeException;

/**
 * A GeoLite2 download did not produce a usable database.
 *
 * Every message here is written for the person running the command, because
 * that is the only place it surfaces. The failures are nearly all
 * configuration mistakes — a wrong licence key, an edition that does not
 * exist, a directory that is not writable — and a stack trace helps with none
 * of them.
 */
final class GeoDatabaseDownloadException extends RuntimeException
{
    public static function noCredentials(): self
    {
        return new self(
            'No MaxMind credentials. GeoLite2 is free but requires an account: sign up at '
            .'https://www.maxmind.com/en/geolite2/signup, create a licence key, then set '
            .'MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY — or pass --account-id and --key.'
        );
    }

    public static function unknownEdition(string $edition, string $known): self
    {
        return new self(sprintf('"%s" is not a GeoLite2 edition. Available: %s.', $edition, $known));
    }

    public static function rejected(int $status): self
    {
        return new self(match ($status) {
            401, 403 => 'MaxMind rejected the credentials. Check the account ID and licence key — '
                .'note that the account ID is a number, not an email address.',
            404 => 'MaxMind has no such database. Check the edition name.',
            429 => 'MaxMind is rate limiting this account. GeoLite2 updates twice a week, '
                .'so downloading more often than that is never necessary.',
            default => sprintf('MaxMind returned HTTP %d.', $status),
        });
    }

    public static function unreachable(string $reason): self
    {
        return new self(sprintf('Could not reach MaxMind: %s', $reason));
    }

    /**
     * A truncated or tampered download, caught before it can replace a
     * database that currently works.
     */
    public static function checksumMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'The download is corrupt: MaxMind published sha256 %s, the file on disk is %s. Nothing was replaced.',
            substr($expected, 0, 16).'…',
            substr($actual, 0, 16).'…',
        ));
    }

    public static function notAnArchive(string $reason): self
    {
        return new self(sprintf('The download could not be unpacked: %s', $reason));
    }

    public static function noDatabaseInArchive(): self
    {
        return new self('The archive unpacked but contained no .mmdb file.');
    }

    public static function directoryNotWritable(string $directory): self
    {
        return new self(sprintf('Cannot write to %s. Create it, or make it writable by this user.', $directory));
    }

    public static function couldNotInstall(string $destination): self
    {
        return new self(sprintf('Downloaded successfully but could not move the database into %s.', $destination));
    }
}
