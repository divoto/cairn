<?php

declare(strict_types=1);

namespace Divoto\Cairn\Recording;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\DeviceDetector;
use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Contracts\TenantResolver;
use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\GeoLocation;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\GeoPrecision;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Identity\VisitorHasher;
use Divoto\Cairn\Privacy\IpAnonymiser;
use Divoto\Cairn\Support\ChannelClassifier;
use Divoto\Cairn\Support\RouteNameGrouper;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

/**
 * Turns a request into an {@see Entry}.
 *
 * **This is the only place in Cairn that touches an IP address.** It arrives
 * as `$request->ip()`, is used to derive the visitor hash and to look up a
 * location, and goes out of scope when this method returns. It is never
 * assigned to a property of this class, never passed to anything that stores,
 * and never reaches the entry that comes out.
 *
 * Everything downstream of here — ingest, storage, rollup, the dashboard —
 * works with a rotating hash and a country code. That is what makes the
 * "raw IP addresses are never persisted" claim structural rather than a
 * matter of remembering.
 */
final readonly class EntryFactory
{
    public function __construct(
        private VisitorHasher $hasher,
        private SessionResolver $sessions,
        private IpAnonymiser $anonymiser,
        private GeoResolver $geo,
        private DeviceDetector $devices,
        private TenantResolver $tenants,
        private ChannelClassifier $channels,
        private RouteNameGrouper $routes,
        private Config $config,
    ) {}

    /**
     * Build a pageview entry for a request.
     */
    public function pageview(Request $request, ?int $status = null, ?int $durationMs = null): Entry
    {
        return $this->build($request, EntryType::Pageview, status: $status, durationMs: $durationMs);
    }

    /**
     * Build an event or conversion entry for a request.
     *
     * @param  array<string, scalar|null>|null  $properties
     */
    public function named(
        Request $request,
        EntryType $type,
        string $name,
        ?array $properties = null,
        ?string $value = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
    ): Entry {
        return $this->build(
            $request,
            $type,
            name: $name,
            properties: $properties,
            value: $value,
            subjectType: $subjectType,
            subjectId: $subjectId,
        );
    }

    /**
     * The visitor hash for a request.
     *
     * Exposed so presence and unique counting can key on the same value
     * without rebuilding a whole entry.
     */
    public function visitorFor(Request $request): string
    {
        return $this->hasher->hash($this->ip($request), $request->userAgent());
    }

    /**
     * @param  array<string, scalar|null>|null  $properties
     */
    private function build(
        Request $request,
        EntryType $type,
        ?string $name = null,
        ?int $status = null,
        ?int $durationMs = null,
        ?array $properties = null,
        ?string $value = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
    ): Entry {
        $now = CarbonImmutable::now('UTC');

        // The address exists from here to the end of this block, and nowhere
        // else. Both uses are deliberate and both discard it.
        $ip = $this->ip($request);
        $visitor = $this->hasher->hash($ip, $request->userAgent());
        $location = $this->locate($ip);
        unset($ip);

        $tenantId = $this->tenants->resolve();
        $session = $this->sessions->resolve($visitor, $now, $tenantId);
        $campaign = $this->channels->campaign($request);
        $device = $this->devices->detect($request->userAgent(), $this->hints($request));

        return new Entry(
            occurredAt: $now,
            type: $type,
            visitor: $visitor,
            session: $session->id,
            name: $name,
            route: $this->routes->group($request),
            url: $this->routes->url($request),
            referrerHost: $this->channels->referrerHost($request),
            channel: $this->channels->classify($request),
            utmSource: $campaign['utm_source'],
            utmMedium: $campaign['utm_medium'],
            utmCampaign: $campaign['utm_campaign'],
            utmTerm: $campaign['utm_term'],
            utmContent: $campaign['utm_content'],
            country: $location->country,
            region: $location->region,
            city: $location->city,
            deviceType: $device->type,
            browser: $device->browser,
            os: $device->os,
            language: $this->language($request),
            status: $status,
            durationMs: $durationMs,
            value: $value,
            properties: $properties,
            subjectType: $subjectType,
            subjectId: $subjectId,
            userId: $this->userId($request),
            tenantId: $tenantId,
        );
    }

    /**
     * Look up a location, then throw away anything finer than configured.
     *
     * The address is masked before the resolver sees it, and the result is
     * reduced before it can reach a column — so a resolver that knows the
     * street is harmless on a country-precision deployment.
     */
    private function locate(string $ip): GeoLocation
    {
        $precision = GeoPrecision::tryFrom(
            is_string($this->config->get('cairn.privacy.geo_precision'))
                ? $this->config->get('cairn.privacy.geo_precision')
                : 'country'
        ) ?? GeoPrecision::Country;

        if ($precision === GeoPrecision::None || ! $this->anonymiser->isRoutable($ip)) {
            return GeoLocation::unknown();
        }

        $masked = $this->anonymiser->anonymise($ip);

        if ($masked === null) {
            return GeoLocation::unknown();
        }

        return ($this->geo->resolve($masked) ?? GeoLocation::unknown())->reduceTo($precision);
    }

    /**
     * The authenticated user's id, but only when explicitly opted in.
     *
     * `privacy.track_user_id` is false by default and its config block
     * explains that enabling it makes the analytics data personal data. This
     * is the only place the value is read, so there is one line to audit.
     */
    private function userId(Request $request): int|string|null
    {
        if ($this->config->get('cairn.privacy.track_user_id') !== true) {
            return null;
        }

        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * The client's address.
     *
     * Kept in one method so that the set of places an address is read is
     * exactly one line long.
     */
    private function ip(Request $request): string
    {
        return $request->ip() ?? '';
    }

    /**
     * The low-entropy client hints, if the browser sent any.
     *
     * Cairn never requests high-entropy hints via `Accept-CH`: asking the
     * browser for model, architecture or full version list would make
     * fingerprinting easier, which is the opposite of the point.
     */
    private function hints(Request $request): ClientHints
    {
        $platform = $request->headers->get('sec-ch-ua-platform');
        $mobile = $request->headers->get('sec-ch-ua-mobile');
        $brand = $request->headers->get('sec-ch-ua');

        return new ClientHints(
            platform: is_string($platform) ? trim($platform, '"') : null,
            mobile: is_string($mobile) ? $mobile === '?1' : null,
            brand: is_string($brand) ? $brand : null,
        );
    }

    /**
     * The primary language, as a short tag.
     *
     * Only the first tag, and only its first eight characters — a full
     * `Accept-Language` header is a fingerprinting signal in its own right.
     */
    private function language(Request $request): ?string
    {
        $header = $request->headers->get('accept-language');

        if (! is_string($header) || $header === '') {
            return null;
        }

        $first = trim(explode(',', $header)[0]);
        $tag = trim(explode(';', $first)[0]);

        return $tag === '' ? null : mb_substr($tag, 0, 8);
    }
}
