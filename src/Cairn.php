<?php

declare(strict_types=1);

namespace Divoto\Cairn;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\DeclineReason;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Recording\EntryFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * The public face of the package.
 *
 * Everything an application calls goes through here, so the surface a user has
 * to learn is one class. Nothing on it throws: a failure inside Cairn is
 * reported and swallowed, because an analytics package that can take a site
 * offline is a worse problem than missing analytics.
 */
final class Cairn
{
    /**
     * Paths and route names ignored for the remainder of this request.
     *
     * @var list<string>
     */
    private array $ignored = [];

    public function __construct(
        private readonly EntryFactory $entries,
        private readonly PrivacyGate $gate,
        private readonly Ingest $ingest,
        private readonly Storage $storage,
        private readonly UniqueCounter $uniques,
        private readonly Presence $presence,
        private readonly SessionResolver $sessions,
    ) {}

    /**
     * Record a named event.
     *
     * @param  array<string, scalar|null>  $properties
     */
    public function event(string $name, array $properties = [], ?Request $request = null): void
    {
        $this->named(EntryType::Event, $name, $properties, null, $request);
    }

    /**
     * Record a named conversion, optionally carrying a value.
     *
     * The value is taken as a string to avoid float drift on money. Passing a
     * float works and is converted, but a caller holding a decimal string
     * should pass it through untouched.
     *
     * @param  array<string, scalar|null>  $properties
     */
    public function conversion(
        string $name,
        float|string|null $value = null,
        array $properties = [],
        ?Request $request = null,
    ): void {
        $this->named(
            EntryType::Conversion,
            $name,
            $properties,
            $value === null ? null : (is_string($value) ? $value : number_format($value, 2, '.', '')),
            $request,
        );
    }

    /**
     * Record an entry that the caller has built themselves.
     *
     * The privacy gate is not consulted here — a caller constructing an Entry
     * has already decided. Everything Cairn builds internally goes through the
     * gate first.
     */
    public function record(Entry $entry): void
    {
        $this->safely(function () use ($entry): void {
            $this->ingest->record($entry);
        });
    }

    /**
     * Ignore a path or route name for the rest of this request.
     *
     * Useful inside a controller that has decided this particular response is
     * not worth measuring — an empty search, a redirect, a health probe
     * answered from a normal route.
     */
    public function ignore(string ...$patterns): void
    {
        foreach ($patterns as $pattern) {
            $this->ignored[] = $pattern;
        }
    }

    /**
     * Whether something has been ignored at runtime for this request.
     */
    public function isIgnored(Request $request): bool
    {
        foreach ($this->ignored as $pattern) {
            if (Str::is($pattern, $request->path())
                || Str::is($pattern, '/'.$request->path())) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many visitors are on the site right now.
     */
    public function live(): int
    {
        return $this->safely(fn (): int => $this->presence->count()) ?? 0;
    }

    /**
     * Flush everything buffered during this request into storage.
     *
     * Called from the terminating callback. Nothing else should call it.
     */
    public function digest(): int
    {
        return $this->safely(fn (): int => $this->ingest->digest($this->storage)) ?? 0;
    }

    /**
     * Mark a visitor as active, and count them towards today's uniques.
     */
    public function touch(Request $request, ?string $page = null): void
    {
        $this->safely(function () use ($request, $page): void {
            $visitor = $this->entries->visitorFor($request);
            $day = CarbonImmutable::now('UTC')->format('Y-m-d');

            $this->presence->touch($visitor, $page);
            $this->uniques->add($day, 'overall', $visitor);
        });
    }

    /**
     * Why the current request would not be recorded, or null if it would be.
     *
     * Exposed for debugging and for `cairn:doctor`; nothing in the recording
     * path needs it.
     */
    public function decide(Request $request): ?DeclineReason
    {
        return $this->gate->decide($request, $this->sampleRate(), PageViews::class);
    }

    /**
     * The session resolver, for callers that need to close or inspect a visit.
     */
    public function sessions(): SessionResolver
    {
        return $this->sessions;
    }

    /**
     * Build and buffer a named entry, subject to the privacy gate.
     *
     * @param  array<string, scalar|null>  $properties
     */
    private function named(
        EntryType $type,
        string $name,
        array $properties,
        ?string $value,
        ?Request $request,
    ): void {
        $this->safely(function () use ($type, $name, $properties, $value, $request): void {
            $request ??= request();

            if (! $this->gate->allows($request) || $this->isIgnored($request)) {
                return;
            }

            $this->ingest->record($this->entries->named(
                $request,
                $type,
                $name,
                $properties === [] ? null : $properties,
                $value,
            ));
        });
    }

    /**
     * Run something, reporting any failure rather than letting it escape.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The pageview recorder's configured sample rate.
     */
    private function sampleRate(): float
    {
        $rate = config('cairn.recorders.'.PageViews::class.'.sample_rate');

        return is_numeric($rate) ? (float) $rate : 1.0;
    }
}
