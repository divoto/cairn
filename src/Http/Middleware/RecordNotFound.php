<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Middleware;

use Closure;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Recording\EntryFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a request that matched no route.
 *
 * The pageview middleware sits on the web group, and a group only runs once a
 * route has matched — so the most common broken page there is, a link to a
 * URL that does not exist, never reaches it. This one is global, and records
 * exactly that case and nothing else: a request that already has a route
 * belongs to the pageview middleware, and is left alone here.
 *
 * Only a GET answered 404 is recorded, and only one that is not asking for
 * JSON. That is a person following a link. A POST to a missing path, or an API
 * client calling an endpoint that is not there, is not a broken page.
 *
 * The entry is not a pageview. It touches no session, no presence and no
 * unique counter, so a scanner working through a list of paths moves no
 * number on the dashboard except the broken pages.
 *
 * Like the pageview middleware, it does nothing until the response has gone,
 * and swallows its own failures.
 */
final readonly class RecordNotFound
{
    public function __construct(
        private Cairn $cairn,
        private PrivacyGate $gate,
        private EntryFactory $entries,
        private Ingest $ingest,
        private Storage $storage,
        private Config $config,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->isUnmatchedPage($request, $response)) {
                return;
            }

            if ($this->cairn->isIgnored($request)) {
                return;
            }

            if (! $this->gate->allows($request, $this->sampleRate(), PageViews::class)) {
                return;
            }

            $this->ingest->record($this->entries->notFound($request));
            $this->ingest->digest($this->storage);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether this is somebody following a link to a URL with no route.
     */
    private function isUnmatchedPage(Request $request, Response $response): bool
    {
        return $response->getStatusCode() === 404
            && $request->route() === null
            && $request->isMethod('GET')
            && ! $request->expectsJson();
    }

    /**
     * The pageview recorder's rate. A sampled site samples these too, or the
     * broken pages would be counted at a different scale from the routes.
     */
    private function sampleRate(): float
    {
        $rate = $this->config->get('cairn.recorders.'.PageViews::class.'.sample_rate');

        return is_numeric($rate) ? (float) $rate : 1.0;
    }
}
