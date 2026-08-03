<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

use Illuminate\Http\Request;

/**
 * Decides whether the current request may be recorded at all.
 *
 * This is an escape hatch for deployments with obligations Cairn cannot know
 * about — a consent management platform, a regional rule, a per-user setting
 * in the host application. It is consulted by the privacy gate alongside Do
 * Not Track, Global Privacy Control and the built-in opt-out.
 *
 * The shipped default grants, because Cairn's default configuration is
 * cookieless and stores no personal data. Configuring a resolver here does not
 * on its own make a deployment lawful, and nothing in this package is legal
 * advice — it is a hook for a decision the deployer has already made.
 */
interface ConsentResolver
{
    /**
     * Whether recording is permitted for this request.
     *
     * Must not throw. A resolver that fails is treated as denying consent —
     * the safe direction is to record nothing.
     */
    public function granted(Request $request): bool;
}
