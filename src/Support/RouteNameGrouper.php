<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Decides what to call a page.
 *
 * This is the thing an external analytics tag cannot do. A browser tag sees
 * `/orders/8814/invoice` and reports it as its own page, so a busy route
 * fragments into thousands of one-visit URLs and nothing aggregates. Cairn is
 * inside the application, so it can ask the router what that URL *is*.
 *
 * Failing a route name, the URI is collapsed by replacing anything that looks
 * like an identifier with `{id}` — which is a guess, but a far better one than
 * treating every order as a separate page.
 */
final class RouteNameGrouper
{
    /**
     * Matches a segment that is an identifier rather than a name: all digits,
     * a UUID, or a ULID.
     */
    private const IDENTIFIER = '/^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-7][0-9A-HJKMNP-TV-Z]{25})$/i';

    /**
     * The grouping name for a request.
     *
     * Prefers the route name, then the route's URI pattern — which already
     * contains `{order}` style placeholders — and only then falls back to
     * guessing from the path.
     */
    public function group(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $name = $route->getName();

            if (is_string($name) && $name !== '') {
                return $name;
            }

            $uri = $route->uri();

            if ($uri !== '') {
                return '/'.ltrim($uri, '/');
            }
        }

        return $this->collapse($request->path());
    }

    /**
     * Replace identifier-looking segments with a placeholder.
     */
    public function collapse(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        $collapsed = array_map(
            static fn (string $segment): string => preg_match(self::IDENTIFIER, $segment) === 1
                ? '{id}'
                : $segment,
            $segments,
        );

        $joined = implode('/', $collapsed);

        return $joined === '' ? '/' : '/'.$joined;
    }

    /**
     * The URL to store for a request: the path, plus UTM parameters only.
     *
     * Everything else in the query string is discarded. A query string is
     * where password-reset tokens, search terms and session identifiers live,
     * and none of them belong in an analytics table — an entry that recorded
     * `?token=...` would be storing a credential.
     */
    public function url(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        $utm = array_filter(
            $request->query(),
            static fn (string $key): bool => str_starts_with($key, 'utm_'),
            ARRAY_FILTER_USE_KEY,
        );

        if ($utm === []) {
            return $path;
        }

        ksort($utm);

        return $path.'?'.http_build_query($utm);
    }
}
