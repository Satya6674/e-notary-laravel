<?php

namespace App\Support;

/**
 * Reads the TRUSTED_PROXIES setting (config/trustedproxy.php) for enotary:deploy-check (H-2).
 *
 * It only REPORTS: it never changes what Laravel's TrustProxies middleware trusts, and never turns
 * an unsafe or invalid value into something else. Entries are split on commas and trimmed; empty
 * entries (a trailing comma) are ignored. Each entry is classified as:
 *
 *  - wildcard: trusts any directly connecting address, so a client reaching the app directly can
 *    forge its address and HTTPS status: "*", "**", "REMOTE_ADDR" (Laravel and Symfony replace it by
 *    the connecting address), and any CIDR with prefix length 0 (0.0.0.0/0, ::/0).
 *  - broad: Symfony's keywords for every private range ("PRIVATE_SUBNETS", "private_ranges"); valid
 *    behind a load balancer in a private network, but anyone on that network is then trusted.
 *  Keywords are matched in their exact spelling, as Laravel and Symfony do; another casing (for
 *  example "remote_addr") is reported as invalid, because it is ignored there.
 *  - placeholder: an unedited template value such as "<proxy-ip-or-cidr>".
 *  - invalid: not an IPv4/IPv6 address or CIDR range (wrong prefix length, typos, "none" inside a
 *    list). Symfony trusts nothing for such an entry, so the proxy behind it is not recognised.
 *  - valid: a specific IPv4/IPv6 address or a CIDR range with a non-zero prefix length.
 */
final class TrustedProxySettings
{
    /** Exact spellings, as Laravel's TrustProxies and Symfony's Request compare them (case-sensitive). */
    public const WILDCARDS = ['*', '**', 'REMOTE_ADDR'];

    /** Symfony's private-range keywords (Request::setTrustedProxies), exact spellings. */
    public const BROAD = ['PRIVATE_SUBNETS', 'private_ranges'];

    /**
     * @return array{entries: list<string>, valid: list<string>, wildcard: list<string>, broad: list<string>, placeholder: list<string>, invalid: list<string>}
     */
    public static function inspect(?string $proxies): array
    {
        $report = ['entries' => [], 'valid' => [], 'wildcard' => [], 'broad' => [], 'placeholder' => [], 'invalid' => []];

        foreach (explode(',', (string) $proxies) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $report['entries'][] = $entry;
            $report[self::classify($entry)][] = $entry;
        }

        return $report;
    }

    /** @return 'valid'|'wildcard'|'broad'|'placeholder'|'invalid' */
    public static function classify(string $entry): string
    {
        // Keywords only work in their exact spelling; any other casing is an invalid entry, as it is
        // for Symfony (which then trusts nothing for it).
        if (in_array($entry, self::WILDCARDS, true)) {
            return 'wildcard';
        }

        if (in_array($entry, self::BROAD, true)) {
            return 'broad';
        }

        if (str_starts_with($entry, '<') || str_ends_with($entry, '>')) {
            return 'placeholder';
        }

        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false ? 'valid' : 'invalid';
        }

        [$address, $bits] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($bits)) {
            return 'invalid';
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
        $bits = (int) $bits;

        if ($bits > $max) {
            return 'invalid';
        }

        return $bits === 0 ? 'wildcard' : 'valid';
    }
}
