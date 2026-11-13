<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\ServiceCatalogue;
use Illuminate\Support\Facades\Route;

/** Turns config/site_links.php into footer content, keeping only links that work. */
class SiteLinks
{
    public static function footer(): array
    {
        $columns = [];

        foreach (config('site_links.columns') as $column) {
            $links = self::resolve($column['links'] ?? []);
            $categories = self::categories($column['categories'] ?? []);

            if ($links !== [] || $categories !== []) {
                $columns[] = [
                    'title'      => $column['title'],
                    'wide'       => $column['wide'] ?? false,
                    'links'      => $links,
                    'categories' => $categories,
                    'cta'        => self::resolve(array_filter([$column['cta'] ?? null]))[0] ?? null,
                ];
            }
        }

        return $columns;
    }

    /** Catalogue categories with open services, in the configured order. */
    private static function categories(array $categories): array
    {
        $open = app(ServiceCatalogue::class)->openGroups();
        $resolved = [];

        foreach ($categories as $key => $text) {
            if ($category = $open->get($key)) {
                $resolved[] = [
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'text' => $text,
                    'url'  => route('services.index', ['category' => $key]),
                ];
            }
        }

        return $resolved;
    }

    public static function contact(): array
    {
        return [
            'email'  => Setting::get('support_email'),
            'phone'  => config('site_links.phone'),
            'social' => array_values(array_filter(config('site_links.social'), fn (array $link) => filled($link['url'] ?? null))),
        ];
    }

    private static function resolve(array $links): array
    {
        $resolved = [];

        foreach ($links as $link) {
            if ($item = self::link($link)) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    private static function link(array $link): ?array
    {
        if (($link['visible'] ?? true) === false) {
            return null;
        }

        if (isset($link['service'])) {
            $service = app(ServiceCatalogue::class)->find($link['service']);

            return $service && $service['visible'] && $service['open']
                ? ['label' => $service['name'], 'url' => route('services.show', $service['slug'])]
                : null;
        }

        if (filled($link['url'] ?? null)) {
            return ['label' => $link['label'], 'url' => $link['url']];
        }

        if (! Route::has($link['route'] ?? '')) {
            return null;
        }

        $url = route($link['route'], $link['query'] ?? []);

        return ['label' => $link['label'], 'url' => isset($link['fragment']) ? $url.'#'.$link['fragment'] : $url];
    }
}
