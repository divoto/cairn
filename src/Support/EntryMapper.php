<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\Channel;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\OperatingSystem;
use Divoto\Cairn\Enums\ScreenClass;

/**
 * Converts an {@see Entry} to a database row and back.
 *
 * One place, used by every driver. The database and Redis paths must produce
 * identical rows or the two drivers would disagree about what was recorded,
 * and the driver-parity suite exists precisely to catch that.
 */
final class EntryMapper
{
    /**
     * Flatten an entry into a row for `cairn_entries`.
     *
     * @return array<string, mixed>
     */
    public static function toRow(Entry $entry): array
    {
        return [
            'occurred_at' => $entry->occurredAt->toDateTimeString(),
            'type' => $entry->type->value,
            'name' => $entry->name,
            'visitor' => $entry->visitor,
            'session' => $entry->session,
            'route' => $entry->route,
            'url' => $entry->url,
            'referrer_host' => $entry->referrerHost,
            'channel' => $entry->channel?->value,
            'utm_source' => $entry->utmSource,
            'utm_medium' => $entry->utmMedium,
            'utm_campaign' => $entry->utmCampaign,
            'utm_term' => $entry->utmTerm,
            'utm_content' => $entry->utmContent,
            'country' => $entry->country,
            'region' => $entry->region,
            'city' => $entry->city,
            'device_type' => $entry->deviceType?->value,
            'browser' => $entry->browser?->value,
            'os' => $entry->os?->value,
            'screen_class' => $entry->screenClass?->value,
            'language' => $entry->language,
            'status' => $entry->status,
            'duration_ms' => $entry->durationMs,
            'time_on_page' => $entry->timeOnPage,
            'scroll_depth' => $entry->scrollDepth,
            'lcp_ms' => $entry->lcpMs,
            'inp_ms' => $entry->inpMs,
            'cls_milli' => $entry->clsMilli,
            'value' => $entry->value,
            'properties' => $entry->properties === null ? null : json_encode($entry->properties),
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId === null ? null : (string) $entry->subjectId,
            'user_id' => $entry->userId,
            'tenant_id' => $entry->tenantId === null ? '' : (string) $entry->tenantId,
        ];
    }

    /**
     * Serialise an entry for transport through Redis.
     *
     * Binary hashes are base64-encoded: a Redis list holds strings, and a raw
     * 16-byte hash containing a null byte would not survive a JSON round trip.
     */
    public static function serialise(Entry $entry): string
    {
        $row = self::toRow($entry);

        $row['visitor'] = base64_encode($entry->visitor);
        $row['session'] = $entry->session === null ? null : base64_encode($entry->session);

        return (string) json_encode($row);
    }

    /**
     * Rebuild an entry from its serialised form.
     *
     * Returns null for anything malformed rather than throwing. A single
     * corrupt payload must not stop a worker draining the rest of the queue.
     */
    public static function deserialise(string $payload): ?Entry
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return null;
        }

        $occurredAt = $data['occurred_at'] ?? null;
        $type = EntryType::tryFrom(self::string($data['type'] ?? ''));
        $visitor = base64_decode(self::string($data['visitor'] ?? ''), true);

        if (! is_string($occurredAt) || $type === null || $visitor === false || $visitor === '') {
            return null;
        }

        $session = isset($data['session']) && is_string($data['session'])
            ? base64_decode($data['session'], true)
            : null;

        return new Entry(
            occurredAt: CarbonImmutable::parse($occurredAt),
            type: $type,
            visitor: $visitor,
            session: $session === false ? null : $session,
            name: self::nullableString($data['name'] ?? null),
            route: self::nullableString($data['route'] ?? null),
            url: self::nullableString($data['url'] ?? null),
            referrerHost: self::nullableString($data['referrer_host'] ?? null),
            channel: Channel::tryFrom(self::int($data['channel'] ?? null) ?? -1),
            utmSource: self::nullableString($data['utm_source'] ?? null),
            utmMedium: self::nullableString($data['utm_medium'] ?? null),
            utmCampaign: self::nullableString($data['utm_campaign'] ?? null),
            utmTerm: self::nullableString($data['utm_term'] ?? null),
            utmContent: self::nullableString($data['utm_content'] ?? null),
            country: self::nullableString($data['country'] ?? null),
            region: self::nullableString($data['region'] ?? null),
            city: self::nullableString($data['city'] ?? null),
            deviceType: DeviceType::tryFrom(self::int($data['device_type'] ?? null) ?? -1),
            browser: Browser::tryFrom(self::int($data['browser'] ?? null) ?? -1),
            os: OperatingSystem::tryFrom(self::int($data['os'] ?? null) ?? -1),
            screenClass: ScreenClass::tryFrom(self::int($data['screen_class'] ?? null) ?? -1),
            language: self::nullableString($data['language'] ?? null),
            status: self::int($data['status'] ?? null),
            durationMs: self::int($data['duration_ms'] ?? null),
            timeOnPage: self::int($data['time_on_page'] ?? null),
            scrollDepth: self::int($data['scroll_depth'] ?? null),
            lcpMs: self::int($data['lcp_ms'] ?? null),
            inpMs: self::int($data['inp_ms'] ?? null),
            clsMilli: self::int($data['cls_milli'] ?? null),
            value: self::nullableString($data['value'] ?? null),
            properties: self::properties($data['properties'] ?? null),
            subjectType: self::nullableString($data['subject_type'] ?? null),
            subjectId: self::nullableString($data['subject_id'] ?? null),
            userId: self::int($data['user_id'] ?? null),
            tenantId: self::nullableString($data['tenant_id'] ?? null),
        );
    }

    /**
     * Read custom event properties, keeping only scalar values.
     *
     * Nested structures are dropped rather than flattened. A property bag is
     * meant to hold a handful of labels and numbers; anything deeper is either
     * a mistake or an attempt to store a record, and neither belongs in an
     * analytics table.
     *
     * @return array<string, scalar|null>|null
     */
    private static function properties(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return null;
        }

        $properties = [];

        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $properties[(string) $key] = $item;
            }
        }

        return $properties === [] ? null : $properties;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
