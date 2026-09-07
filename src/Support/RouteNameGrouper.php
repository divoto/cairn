<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Divoto\Cairn\Enums\RouteGrouping;
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
 *
 * Which of those answers is wanted is a property of the application, not of
 * Cairn: a site that serves every page from one `/{slug}` route has a single
 * route name for its entire catalogue, and grouping by it says nothing. So the
 * preference order is configurable — see {@see RouteGrouping} and
 * `cairn.recorders.PageViews::class.group_by`.
 */
final readonly class RouteNameGrouper
{
    /**
     * Matches a segment that is an identifier rather than a name: all digits,
     * a UUID, or a ULID.
     */
    private const IDENTIFIER = '/^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-7][0-9A-HJKMNP-TV-Z]{25})$/i';

    /**
     * Defaults to the route name, which is what an unconfigured install gets
     * and what every version before this one did.
     */
    public function __construct(
        private RouteGrouping $grouping = RouteGrouping::Name,
    ) {}

    /**
     * The grouping name for a request.
     *
     * Under the default grouping this prefers the route name, then the route's
     * URI pattern — which already contains `{order}` style placeholders — and
     * only then falls back to guessing from the path. The other groupings skip
     * straight to a later step; all three end at the same fallback, because a
     * request that matched no route still has to be called something.
     */
    public function group(Request $request): string
    {
        $route = $request->route();

        return match ($this->grouping) {
            RouteGrouping::Path => self::collapse($request->path()),
            RouteGrouping::Uri => $this->pattern($route) ?? self::collapse($request->path()),
            RouteGrouping::Name => $this->name($route)
                ?? $this->pattern($route)
                ?? self::collapse($request->path()),
        };
    }

    /**
     * The route's name, if it has a non-empty one.
     */
    private function name(mixed $route): ?string
    {
        if (! $route instanceof Route) {
            return null;
        }

        $name = $route->getName();

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The route's URI pattern, as a path.
     */
    private function pattern(mixed $route): ?string
    {
        if (! $route instanceof Route) {
            return null;
        }

        $uri = $route->uri();

        return $uri === '' ? null : '/'.ltrim($uri, '/');
    }

    /**
     * Replace identifier-looking segments with a placeholder.
     */
    public static function collapse(string $path): string
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
