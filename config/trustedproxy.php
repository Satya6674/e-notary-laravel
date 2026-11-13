<?php

/*
| Trusted reverse proxies (H-2, 2026-11-11).
|
| Laravel's TrustProxies middleware reads 'proxies' on every request, so it works with .env and with
| `php artisan config:cache`. (Reading TRUSTED_PROXIES with env() in bootstrap/app.php did not: that
| code runs before .env is loaded, so the setting was silently ignored.)
|
| TRUSTED_PROXIES has three kinds of value:
|
|   (empty)   Nothing declared: Laravel's default. No proxy is trusted and X-Forwarded-* headers
|             from clients are ignored, with one framework exception: on Laravel Cloud
|             (LARAVEL_CLOUD=1) and on *.on-forge.com / *.on-vapor.com hosts, Laravel itself trusts
|             the connecting address. Fine for local development (XAMPP, artisan serve).
|             enotary:deploy-check asks a production HTTPS site to declare one of the choices below.
|
|   none      DIRECT TLS: nothing sits in front of the application; this server terminates HTTPS
|             itself (for example Apache with its own certificate). No proxy is trusted on ANY host,
|             including the platforms above (passed to Laravel as an empty list, which its platform
|             auto-trust does not override). Case-insensitive. Never treated as an address.
|
|   a list    REVERSE PROXY / LOAD BALANCER in front of the application: its address(es), comma-
|             separated. IPv4/IPv6 addresses and CIDR ranges, for example
|                 127.0.0.1,::1            a reverse proxy on the same server
|                 10.0.0.0/8               a load balancer in a private network
|
| "*" (or REMOTE_ADDR, 0.0.0.0/0, ::/0) trusts whichever address connects directly: safe only when
| the application can be reached SOLELY through the proxy. It is never the default, and
| enotary:deploy-check warns about it, about broad keywords and about invalid entries.
|
| Which forwarded headers a trusted proxy may set is fixed in bootstrap/app.php: X-Forwarded-For and
| X-Forwarded-Proto only.
*/

return (static function (string $raw): array {
    $direct = strcasecmp($raw, 'none') === 0;

    return [
        // What Laravel's TrustProxies uses (vendor TrustProxies::setTrustedProxyIpAddresses):
        //   empty  -> null: Laravel's default. No proxy is trusted, EXCEPT that Laravel itself then
        //             trusts the connecting address on Laravel Cloud (LARAVEL_CLOUD=1) and on
        //             *.on-forge.com / *.on-vapor.com hosts, where its own proxy sits in front.
        //   none   -> []  : an explicit empty list. Trusts no proxy on EVERY host, because Laravel's
        //             platform auto-trust applies only to null. Never the string "none".
        //   a list -> the string, split on commas by TrustProxies.
        'proxies' => match (true) {
            $direct => [],
            $raw === '' => null,
            default => $raw,
        },

        // True only for the explicit "none" declaration (direct TLS), so it can be told apart from
        // an empty, undeclared value. Read by enotary:deploy-check; TrustProxies ignores it.
        'direct_tls' => $direct,
    ];
})(trim((string) env('TRUSTED_PROXIES', '')));
