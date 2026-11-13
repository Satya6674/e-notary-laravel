<?php

namespace App\Support;

use Illuminate\Routing\UrlGenerator;

/**
 * Links that leave the site by email are built on APP_URL, never on the Host header of the request
 * that happened to trigger the email. Otherwise a request sent with a forged Host could make a real
 * email carry a link to someone else's server, along with its token or signature. Laravel's own
 * trusted-host check is skipped when APP_ENV=local, so this does not depend on it.
 */
final class AppUrl
{
    /** APP_URL without a trailing slash. */
    public static function root(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Runs $build with every URL it generates rooted at APP_URL (scheme, host, port and any
     * sub-folder), then puts the generator back as it was for the rest of the request.
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    public static function using(callable $build): mixed
    {
        /** @var UrlGenerator $url */
        $url = app('url');
        $root = self::root();
        $scheme = parse_url($root, PHP_URL_SCHEME);

        $url->forceRootUrl($root);
        $url->forceScheme(is_string($scheme) ? $scheme : null);

        try {
            return $build();
        } finally {
            $url->forceRootUrl(null);
            $url->forceScheme(null);
        }
    }
}
