<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the JSON API unreachable unless it has been switched on.
 *
 * A 404 rather than a 403: to anybody who has not been told the API exists,
 * a disabled endpoint should be indistinguishable from one that was never
 * built.
 *
 * Checked per request rather than at route-registration time, so toggling
 * `cairn.api.enabled` takes effect without a route-cache rebuild — and so the
 * behaviour is testable without tearing down the application.
 */
final readonly class EnsureApiEnabled
{
    public function __construct(
        private Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->config->get('cairn.api.enabled') === true, 404);

        return $next($request);
    }
}
