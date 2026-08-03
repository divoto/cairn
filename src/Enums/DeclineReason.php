<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * Why the privacy gate refused to record a request.
 *
 * The gate returns a reason rather than a bare false so that `cairn:doctor`
 * can explain a silent installation, and so a test can assert *which* rule
 * stopped a request instead of merely that something did. A gate that only
 * says "no" is a gate nobody can debug.
 */
enum DeclineReason: string
{
    /** `cairn.enabled` is false. */
    case Disabled = 'disabled';

    /** The visitor sent `DNT: 1` and `privacy.respect_dnt` is on. */
    case DoNotTrack = 'do_not_track';

    /** The visitor sent `Sec-GPC: 1` and `privacy.respect_gpc` is on. */
    case GlobalPrivacyControl = 'global_privacy_control';

    /** The visitor has opted out through Cairn's own opt-out. */
    case OptedOut = 'opted_out';

    /** The configured consent resolver declined, or threw. */
    case ConsentDenied = 'consent_denied';

    /** The user agent identifies an automated client. */
    case Bot = 'bot';

    /** The path, route name or a configured closure matched an ignore rule. */
    case Ignored = 'ignored';

    /**
     * The browser was speculatively fetching the page.
     *
     * A prefetch is not a visit. Recording one inflates pageviews for pages
     * nobody chose to open, and the inflation is worst on exactly the links a
     * browser guesses are popular.
     */
    case Prefetch = 'prefetch';

    /** The recorder's sample rate excluded this request. */
    case Sampled = 'sampled';

    /**
     * A plain-language explanation, used by `cairn:doctor` and in debugging.
     */
    public function explain(): string
    {
        return match ($this) {
            self::Disabled => 'Cairn is disabled by configuration.',
            self::DoNotTrack => 'The visitor sent Do Not Track and Cairn honours it.',
            self::GlobalPrivacyControl => 'The visitor sent Global Privacy Control and Cairn honours it.',
            self::OptedOut => 'The visitor has opted out of measurement.',
            self::ConsentDenied => 'The configured consent resolver declined.',
            self::Bot => 'The request came from an automated client.',
            self::Ignored => 'The path or route matches an ignore rule.',
            self::Prefetch => 'The browser was prefetching, not visiting.',
            self::Sampled => 'Sampling excluded this request.',
        };
    }

    /**
     * Whether this decision reflects a visitor's expressed wish.
     *
     * These are the reasons that must never be worked around, cached away or
     * made configurable into irrelevance.
     */
    public function isVisitorChoice(): bool
    {
        return match ($this) {
            self::DoNotTrack, self::GlobalPrivacyControl, self::OptedOut, self::ConsentDenied => true,
            default => false,
        };
    }
}
