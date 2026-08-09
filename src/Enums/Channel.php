<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * How a visitor arrived, under a last-click model.
 *
 * Cairn does not implement multi-touch attribution and will not in v1 — the
 * salt rotates daily, so there is no cross-day visitor identity to attribute
 * across in the first place.
 *
 * Backed by small integers because this lands in a tinyint column on a table
 * that is written on every pageview.
 */
enum Channel: int
{
    /** No referrer and no campaign parameters. */
    case Direct = 1;

    /** A referrer that is a known search engine, without paid parameters. */
    case Organic = 2;

    /** A referrer that is a known social network. */
    case Social = 3;

    /** Any other external referrer. */
    case Referral = 4;

    /** Campaign parameters indicating paid acquisition. */
    case Paid = 5;

    /** Campaign parameters indicating an email send. */
    case Email = 6;

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Organic => 'Organic search',
            self::Social => 'Social',
            self::Referral => 'Referral',
            self::Paid => 'Paid',
            self::Email => 'Email',
        };
    }

    /**
     * Whether arriving through this channel implies a campaign.
     */
    public function isCampaign(): bool
    {
        return $this === self::Paid || $this === self::Email;
    }
}
