<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

use Divoto\Cairn\Data\Entry;

/**
 * Accepts entries during a request and hands them to storage later.
 *
 * Ingest exists to keep writes off the request's critical path. An
 * implementation must never write to the database inside {@see self::record()}
 * while a response is still being produced — buffer it, and flush from a
 * `terminating` callback or a separate worker process.
 *
 * Implementations must swallow their own failures. If ingest is broken, the
 * host application still serves the request.
 */
interface Ingest
{
    /**
     * Accept an entry for eventual storage.
     *
     * Must return fast and must not throw.
     */
    public function record(Entry $entry): void;

    /**
     * Flush buffered entries into storage.
     *
     * @return int The number of entries flushed.
     */
    public function digest(Storage $storage): int;

    /**
     * Discard anything buffered without storing it.
     *
     * Used when a buffer has grown past its configured bound and shedding load
     * is preferable to holding memory, and by the null driver.
     */
    public function trim(): void;
}
