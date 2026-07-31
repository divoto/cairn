<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the dashboard behind the `viewCairn` gate.
 *
 * A 403 rather than a redirect: the dashboard is not part of the application's
 * own authentication flow, and bouncing an unauthorised visitor to a login
 * page would confirm the dashboard exists at that URL.
 */
final readonly class Authorize
{
    public function __construct(
        private Gate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->gate->allows('viewCairn', [$request->user()]), 403);

        return $next($request);
    }
}
