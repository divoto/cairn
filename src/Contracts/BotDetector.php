<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

/**
 * Decides whether a request came from an automated client.
 *
 * Bot traffic is discarded before an entry is built, not filtered out at
 * report time. Storing it and hiding it would leave every raw number wrong and
 * every drill-down misleading.
 *
 * Detection is user-agent based and therefore imperfect: a bot that lies about
 * its user agent is indistinguishable from a browser at this layer. Cairn does
 * not attempt behavioural bot detection — that would mean profiling visitors,
 * which is the thing this package exists not to do.
 */
interface BotDetector
{
    /**
     * Whether the given user agent identifies an automated client.
     *
     * A null or empty user agent is a judgement call left to the
     * implementation; the shipped default treats it as a bot.
     */
    public function isBot(?string $userAgent): bool;
}
