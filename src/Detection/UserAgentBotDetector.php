<?php

declare(strict_types=1);

namespace Divoto\Cairn\Detection;

use Divoto\Cairn\Contracts\BotDetector;

/**
 * Identifies automated clients from the user agent string.
 *
 * Bot traffic is discarded before an entry is built rather than filtered at
 * report time. Storing it and hiding it would leave every raw number wrong and
 * every drill-down misleading.
 *
 * This is deliberately signature-based and therefore imperfect: a crawler that
 * lies about its user agent is indistinguishable from a browser here. Cairn
 * does not do behavioural bot detection — that means profiling visitors, which
 * is the thing this package exists not to do. A site with a real bot problem
 * should block at the edge, where the decision belongs.
 */
final class UserAgentBotDetector implements BotDetector
{
    /**
     * Substrings that identify an automated client, lowercased.
     *
     * @var list<string>
     */
    private const SIGNATURES = [
        // Generic self-identification. Most well-behaved crawlers say so.
        'bot', 'crawler', 'spider', 'crawl', 'slurp', 'scraper',

        // Libraries and tools that are never a person browsing.
        'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'java/', 'okhttp', 'axios/', 'node-fetch', 'guzzlehttp', 'httpie',
        'postmanruntime', 'insomnia', 'libwww-perl', 'lwp::', 'phantomjs',
        'headlesschrome', 'puppeteer', 'playwright', 'selenium', 'cypress',

        // Monitoring, uptime and preview fetchers.
        'pingdom', 'uptimerobot', 'statuscake', 'newrelic', 'datadog',
        'site24x7', 'gtmetrix', 'lighthouse', 'pagespeed', 'chrome-lighthouse',
        'ahrefs', 'semrush', 'mj12', 'dotbot', 'petalbot', 'dataforseo',

        // Link unfurlers. These fetch a page to build a preview card; a
        // person may never see it.
        'facebookexternalhit', 'twitterbot', 'slackbot', 'discordbot',
        'telegrambot', 'whatsapp', 'linkedinbot', 'embedly', 'quora link',
        'skypeuripreview', 'redditbot', 'applebot', 'bingpreview',

        // Feed readers.
        'feedfetcher', 'feedly', 'inoreader', 'newsblur',
    ];

    public function isBot(?string $userAgent): bool
    {
        // A request with no user agent at all is not a browser. Treating it as
        // one is how a scripted flood ends up counted as traffic.
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        $needle = strtolower($userAgent);

        foreach (self::SIGNATURES as $signature) {
            if (str_contains($needle, $signature)) {
                return true;
            }
        }

        return false;
    }
}
