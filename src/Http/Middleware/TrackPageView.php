<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Middleware;

use Closure;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Recording\EntryFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a pageview for each request that passes the privacy gate.
 *
 * **Nothing here runs before the response.** `handle()` notes the start time
 * and gets out of the way; every decision and every write happens in
 * `terminate()`, after the response has been sent to the visitor.
 *
 * The whole method body is wrapped. A failure inside Cairn must never become
 * the host application's failure — and by the time this runs, the visitor
 * already has their page, so there is nothing left to fail *at*.
 */
final class TrackPageView
{
    /**
     * When the request started, for measuring server response time.
     */
    private ?float $startedAt = null;

    public function __construct(
        private readonly Cairn $cairn,
        private readonly PrivacyGate $gate,
        private readonly EntryFactory $entries,
        private readonly Ingest $ingest,
        private readonly Storage $storage,
        private readonly UniqueCounter $uniques,
        private readonly Presence $presence,
        private readonly SessionResolver $sessions,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->startedAt = microtime(true);

        return $next($request);
    }

    /**
     * Record the pageview, after the response has gone out.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            if ($this->cairn->isIgnored($request)) {
                return;
            }

            if (! $this->gate->allows($request, $this->sampleRate(), PageViews::class)) {
                return;
            }

            $entry = $this->entries->pageview(
                $request,
                status: $response->getStatusCode(),
                durationMs: $this->elapsedMilliseconds(),
            );

            $this->ingest->record($entry);

            // Presence and unique counting key on the same visitor hash the
            // entry carries, so they cannot disagree with it.
            $this->presence->touch($entry->visitor, $entry->route);
            $this->uniques->add($entry->occurredAt->format('Y-m-d'), 'overall', $entry->visitor);

            if ($entry->route !== null) {
                $this->uniques->add($entry->occurredAt->format('Y-m-d'), 'route:'.$entry->route, $entry->visitor);
            }

            $session = $this->sessions->resolve($entry->visitor, $entry->occurredAt);
            $this->sessions->record($session, $entry->occurredAt, $entry->url);

            $this->ingest->digest($this->storage);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether the pageview recorder is switched on.
     */
    private function enabled(): bool
    {
        return $this->config->get('cairn.enabled') === true
            && $this->config->get('cairn.recorders.'.PageViews::class.'.enabled') !== false;
    }

    private function sampleRate(): float
    {
        $rate = $this->config->get('cairn.recorders.'.PageViews::class.'.sample_rate');

        return is_numeric($rate) ? (float) $rate : 1.0;
    }

    /**
     * Server response time, or null if the timer never started.
     */
    private function elapsedMilliseconds(): ?int
    {
        if ($this->startedAt === null) {
            return null;
        }

        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
