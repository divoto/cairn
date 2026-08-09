<?php

declare(strict_types=1);

namespace Divoto\Cairn\Privacy;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A visitor's standing refusal to be measured.
 *
 * **This is the one cookie Cairn will ever set**, and it exists only to
 * remember a "no". The irony is not lost: a cookieless analytics package
 * storing a cookie. But the alternative is worse.
 *
 * Cairn's visitor hash rotates every 24 hours, so there is nothing durable to
 * attach a suppression list to — an opt-out recorded server-side against
 * today's hash would silently expire at midnight, and the visitor who had said
 * no would be measured again tomorrow. Honouring a refusal requires
 * remembering it on the only thing that persists: the visitor's own device.
 *
 * The cookie holds the single character "1". It carries no identifier, cannot
 * be used to recognise anyone, and is readable by the visitor. It is
 * `SameSite=Lax` and not `HttpOnly`, so a deployer's own consent banner can
 * check and set it from JavaScript.
 */
final readonly class OptOut
{
    /**
     * The cookie name. Deliberately obvious, so a visitor inspecting their own
     * cookies can see what it is without having to look it up.
     */
    public const COOKIE = 'cairn_opt_out';

    /**
     * How long the refusal is remembered, in minutes. Five years.
     *
     * Long, on purpose. A refusal that quietly expires is not a refusal
     * honoured, and asking somebody the same question every year is the
     * behaviour this package exists to avoid.
     */
    private const LIFETIME = 60 * 24 * 365 * 5;

    /**
     * Whether this visitor has opted out.
     */
    public function has(Request $request): bool
    {
        return $request->cookies->get(self::COOKIE) !== null;
    }

    /**
     * The cookie that records a refusal.
     *
     * Returned rather than queued, so the caller attaches it to a response
     * they control — Cairn never reaches into the response cycle to set it.
     */
    public function cookie(): Cookie
    {
        return new Cookie(
            name: self::COOKIE,
            value: '1',
            expire: time() + self::LIFETIME * 60,
            path: '/',
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * The cookie that withdraws a refusal.
     */
    public function forgetCookie(): Cookie
    {
        return new Cookie(
            name: self::COOKIE,
            value: '',
            expire: time() - 3600,
            path: '/',
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
