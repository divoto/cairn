<?php

declare(strict_types=1);

namespace Divoto\Cairn\Consent;

use Divoto\Cairn\Contracts\ConsentResolver;
use Illuminate\Http\Request;

/**
 * The shipped default consent resolver: it grants.
 *
 * Cairn's default configuration writes no cookie, touches no session, stores
 * no IP address and cannot follow a visitor across days, so there is nothing
 * for this resolver to gate. A deployer with obligations Cairn cannot know
 * about — a consent management platform, a regional rule, a per-user setting —
 * configures their own resolver in `cairn.privacy.consent_resolver`.
 *
 * Note that granting here does not bypass the rest of the privacy gate: Do Not
 * Track, Global Privacy Control and the per-user opt-out are all still
 * honoured, and each of them can independently stop a request being recorded.
 *
 * Named for what it does rather than `NullConsentResolver`, because "null"
 * would leave it ambiguous whether the default is to permit or to refuse.
 */
final class GrantingConsentResolver implements ConsentResolver
{
    public function granted(Request $request): bool
    {
        return true;
    }
}
