<?php

declare(strict_types=1);

namespace Divoto\Cairn\Ingest;

use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Buffers entries in memory and writes them once, after the response.
 *
 * This is the driver a deployment gets without installing anything. It holds
 * entries in a plain array for the life of the request and flushes them from a
 * `terminating` callback, so the visitor waits for zero database round trips.
 *
 * Failure is swallowed and reported, never rethrown. If Cairn's storage is
 * down, the host application must still serve requests — an analytics package
 * that can take a site offline is a worse problem than missing analytics.
 */
final class DatabaseIngest implements Ingest
{
    /**
     * Entries awaiting a flush.
     *
     * @var list<Entry>
     */
    private array $buffer = [];

    public function __construct(
        private readonly Config $config,
    ) {}

    public function record(Entry $entry): void
    {
        // Shed rather than grow without bound. A request that produces more
        // entries than the buffer holds is either a bug or an attack, and
        // neither is worth exhausting memory over.
        if (count($this->buffer) >= $this->bufferLimit()) {
            return;
        }

        $this->buffer[] = $entry;
    }

    public function digest(Storage $storage): int
    {
        if ($this->buffer === []) {
            return 0;
        }

        $entries = $this->buffer;
        $this->buffer = [];

        try {
            /** @var Collection<int, Entry> $collection */
            $collection = new Collection($entries);

            $storage->store($collection);

            return count($entries);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function trim(): void
    {
        $this->buffer = [];
    }

    /**
     * How many entries are currently buffered.
     *
     * Exposed for tests and for `cairn:doctor`; nothing in the recording path
     * needs it.
     */
    public function buffered(): int
    {
        return count($this->buffer);
    }

    /**
     * The configured buffer ceiling.
     */
    private function bufferLimit(): int
    {
        $limit = $this->config->get('cairn.ingest.buffer');

        return is_numeric($limit) ? max(1, (int) $limit) : 500;
    }
}
