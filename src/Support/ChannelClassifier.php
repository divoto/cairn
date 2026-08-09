<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Divoto\Cairn\Enums\Channel;
use Illuminate\Http\Request;

/**
 * Works out how a visitor arrived, under a last-click model.
 *
 * Cairn does not attempt multi-touch attribution and will not: the salt
 * rotates every 24 hours, so there is no cross-day journey to attribute
 * across. What a site owner gets is "how did this visit start", which is
 * honest, and not "which of six touches deserves credit", which would be
 * invented.
 *
 * Only the referrer's **host** is ever read or stored. A full referring URL
 * can carry a search query, a session token, or the title of a private
 * document, and none of that belongs in an analytics table.
 */
final class ChannelClassifier
{
    /**
     * Hosts that indicate organic search.
     *
     * Matched as suffixes, so `www.google.co.uk` matches `google.`.
     *
     * @var list<string>
     */
    private const SEARCH = [
        'google.', 'bing.', 'duckduckgo.', 'yahoo.', 'yandex.', 'baidu.',
        'ecosia.', 'startpage.', 'brave.com', 'qwant.', 'search.marcia',
        'searx.', 'kagi.com', 'perplexity.ai', 'chatgpt.com', 'chat.openai.com',
        'claude.ai', 'gemini.google.com', 'copilot.microsoft.com',
    ];

    /**
     * Hosts that indicate a social referral.
     *
     * @var list<string>
     */
    private const SOCIAL = [
        'facebook.', 'fb.', 'instagram.', 'twitter.', 'x.com', 't.co',
        'linkedin.', 'lnkd.in', 'reddit.', 'pinterest.', 'tiktok.',
        'youtube.', 'youtu.be', 'mastodon.', 'bsky.app', 'threads.net',
        'news.ycombinator.com', 'lobste.rs', 'discord.', 'slack.',
        'telegram.', 't.me', 'whatsapp.', 'vk.com', 'tumblr.',
    ];

    /**
     * UTM mediums that indicate paid acquisition.
     *
     * @var list<string>
     */
    private const PAID = ['cpc', 'ppc', 'paid', 'paidsearch', 'display', 'banner', 'retargeting', 'cpm'];

    /**
     * UTM mediums that indicate an email send.
     *
     * @var list<string>
     */
    private const EMAIL = ['email', 'e-mail', 'newsletter', 'mail'];

    /**
     * Classify a request.
     *
     * Campaign parameters win over the referrer: a visitor arriving from a
     * Google ad has both a search-engine referrer and `utm_medium=cpc`, and
     * counting that as organic would quietly overstate free traffic.
     */
    public function classify(Request $request): Channel
    {
        $medium = strtolower(trim($this->queryString($request, 'utm_medium')));

        if ($medium !== '') {
            if (in_array($medium, self::PAID, true)) {
                return Channel::Paid;
            }

            if (in_array($medium, self::EMAIL, true)) {
                return Channel::Email;
            }
        }

        // A campaign with an unrecognised medium is still a campaign, not
        // direct traffic.
        $host = $this->referrerHost($request);

        if ($host === null) {
            return $medium !== '' || $this->queryString($request, 'utm_source') !== ''
                ? Channel::Referral
                : Channel::Direct;
        }

        if ($this->matches($host, self::SEARCH)) {
            return Channel::Organic;
        }

        if ($this->matches($host, self::SOCIAL)) {
            return Channel::Social;
        }

        return Channel::Referral;
    }

    /**
     * The host of the referring page, or null when there is none or it is this
     * site.
     *
     * A referral from the site to itself is internal navigation, not
     * acquisition, and counting it would make every multi-page visit look like
     * a fresh referral.
     */
    public function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if (! is_string($referrer) || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return $host === strtolower($request->getHost()) ? null : $host;
    }

    /**
     * The UTM parameters on a request, trimmed and length-capped.
     *
     * @return array<string, string|null>
     */
    public function campaign(Request $request): array
    {
        $fields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $out = [];

        foreach ($fields as $field) {
            $value = trim($this->queryString($request, $field));

            // The column is 128 characters. A longer value is either a mistake
            // or an attempt to use the analytics table as storage.
            $out[$field] = $value === '' ? null : mb_substr($value, 0, 128);
        }

        return $out;
    }

    /**
     * Whether a host ends with, or equals, any of the given needles.
     *
     * @param  list<string>  $needles
     */
    private function matches(string $host, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
