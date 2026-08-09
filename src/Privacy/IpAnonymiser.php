<?php

declare(strict_types=1);

namespace Divoto\Cairn\Privacy;

/**
 * Masks an IP address before it reaches a geo resolver.
 *
 * Country, and even region, are recoverable from a truncated address, so there
 * is no reason to hand a resolver the full one. IPv4 loses its final octet and
 * IPv6 everything below the /48 — enough to place a request on a map, not
 * enough to identify a household.
 *
 * This is defence in depth rather than the main protection. The main
 * protection is that no address of any kind is ever persisted; this narrows
 * what a third-party resolver, or a resolver's log, could ever see.
 *
 * Every method here takes an address and returns one. Nothing is stored.
 */
final class IpAnonymiser
{
    /**
     * Mask an address, returning null if it is not a valid IP.
     *
     * A malformed address is not passed through unchanged: an unparseable
     * value is more likely a spoofed header than a real client, and forwarding
     * it to a resolver would be handing on something unexamined.
     */
    public function anonymise(string $ip): ?string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->maskIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->maskIpv6($ip);
        }

        return null;
    }

    /**
     * Whether an address is one a geo lookup could never usefully answer.
     *
     * Loopback, link-local and private ranges resolve to nothing, so the
     * lookup is skipped rather than performed and discarded — which matters
     * when the resolver is a paid or rate-limited service.
     */
    public function isRoutable(string $ip): bool
    {
        return filter_var(
            trim($ip),
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Zero the final octet: 203.0.113.42 becomes 203.0.113.0.
     */
    private function maskIpv4(string $ip): string
    {
        $octets = explode('.', $ip);
        $octets[3] = '0';

        return implode('.', $octets);
    }

    /**
     * Keep the first 48 bits and zero the rest.
     *
     * Done on the packed binary form rather than by string surgery, so that
     * every textual spelling of the same address — compressed, expanded, or
     * IPv4-mapped — masks identically.
     */
    private function maskIpv6(string $ip): string
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        $masked = substr($packed, 0, 6).str_repeat("\0", 10);
        $text = inet_ntop($masked);

        return $text === false ? $ip : $text;
    }
}
