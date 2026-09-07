<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\Channel;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\OperatingSystem;
use Divoto\Cairn\Enums\ScreenClass;

/**
 * One recorded thing: a pageview, an event, or a conversion.
 *
 * This is the unit that crosses the Ingest boundary and lands in
 * `cairn_entries`. It is deliberately flat — it maps to a row, and a row is
 * what a driver has to write.
 *
 * **There is no IP address on this object, and there never will be.** An IP
 * exists in memory only long enough to derive `$visitor` and perform a geo
 * lookup; by the time an Entry is constructed, it is gone. The same applies to
 * the user agent string, which produces {@see Device} and is then discarded.
 *
 * `$visitor` and `$session` are raw 16-byte binary strings, not hex. They are
 * derived from a salt that rotates every 24 hours, so the same person browsing
 * on two consecutive days produces two unrelated values — by construction, not
 * by policy.
 */
final readonly class Entry
{
    /**
     * @param  string  $visitor  Raw 16-byte daily-rotating visitor hash.
     * @param  string|null  $session  Raw 16-byte session identifier.
     * @param  string|null  $url  Path only. The query string is stripped except for UTM parameters.
     * @param  string|null  $referrerHost  Host only — never a full referring URL.
     * @param  string|null  $value  Conversion value as a decimal string, to avoid float drift on money.
     * @param  array<string, scalar|null>|null  $properties  Custom event properties.
     * @param  int|string|null  $userId  Only ever set when `privacy.track_user_id` is explicitly enabled.
     */
    public function __construct(
        public CarbonImmutable $occurredAt,
        public EntryType $type,
        public string $visitor,
        public ?string $session = null,
        public ?string $name = null,
        public ?string $route = null,
        public ?string $url = null,
        public ?string $referrerHost = null,
        public ?Channel $channel = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
        public ?string $utmTerm = null,
        public ?string $utmContent = null,
        public ?string $country = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?DeviceType $deviceType = null,
        public ?Browser $browser = null,
        public ?OperatingSystem $os = null,
        public ?ScreenClass $screenClass = null,
        public ?string $language = null,
        public ?int $status = null,
        public ?int $durationMs = null,
        public ?int $timeOnPage = null,
        public ?int $scrollDepth = null,
        /** Largest Contentful Paint in milliseconds, from the beacon. */
        public ?int $lcpMs = null,
        /** Interaction to Next Paint in milliseconds, from the beacon. */
        public ?int $inpMs = null,
        /** Cumulative Layout Shift times a thousand, from the beacon. */
        public ?int $clsMilli = null,
        public ?string $value = null,
        public ?array $properties = null,
        public ?string $subjectType = null,
        public int|string|null $subjectId = null,
        public int|string|null $userId = null,
        public int|string|null $tenantId = null,
    ) {}

    /**
     * Return a copy carrying the given location, at whatever precision the
     * location has already been reduced to.
     */
    public function withLocation(GeoLocation $location): self
    {
        return $this->with(
            country: $location->country,
            region: $location->region,
            city: $location->city,
        );
    }

    /**
     * Return a copy carrying the given device classification.
     */
    public function withDevice(Device $device): self
    {
        return $this->with(
            deviceType: $device->type,
            browser: $device->browser,
            os: $device->os,
        );
    }

    /**
     * Return a copy with the given fields replaced.
     *
     * Kept private and fed by the named `with*` helpers above so that callers
     * cannot rebuild an entry with an arbitrary field set — in particular, so
     * that no code path can quietly attach a user id to an entry that the
     * privacy configuration says should not carry one.
     *
     * @param  array<string, scalar|null>|null  $properties
     */
    private function with(
        ?string $country = null,
        ?string $region = null,
        ?string $city = null,
        ?DeviceType $deviceType = null,
        ?Browser $browser = null,
        ?OperatingSystem $os = null,
        ?array $properties = null,
    ): self {
        return new self(
            occurredAt: $this->occurredAt,
            type: $this->type,
            visitor: $this->visitor,
            session: $this->session,
            name: $this->name,
            route: $this->route,
            url: $this->url,
            referrerHost: $this->referrerHost,
            channel: $this->channel,
            utmSource: $this->utmSource,
            utmMedium: $this->utmMedium,
            utmCampaign: $this->utmCampaign,
            utmTerm: $this->utmTerm,
            utmContent: $this->utmContent,
            country: $country ?? $this->country,
            region: $region ?? $this->region,
            city: $city ?? $this->city,
            deviceType: $deviceType ?? $this->deviceType,
            browser: $browser ?? $this->browser,
            os: $os ?? $this->os,
            screenClass: $this->screenClass,
            language: $this->language,
            status: $this->status,
            durationMs: $this->durationMs,
            timeOnPage: $this->timeOnPage,
            scrollDepth: $this->scrollDepth,
            lcpMs: $this->lcpMs,
            inpMs: $this->inpMs,
            clsMilli: $this->clsMilli,
            value: $this->value,
            properties: $properties ?? $this->properties,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            userId: $this->userId,
            tenantId: $this->tenantId,
        );
    }
}
