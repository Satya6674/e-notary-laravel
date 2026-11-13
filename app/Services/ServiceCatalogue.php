<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The one list of services. Navigation, the home page, public service pages, the
 * citizen portal, admin management and the footer all read it from here.
 *
 * Defaults live in config/service_catalogue.php. Administrator changes are stored
 * per service in the "service_catalogue" setting, so no service is defined twice.
 */
class ServiceCatalogue
{
    private const SETTING = 'service_catalogue';

    private ?Collection $services = null;

    /** Every service, in display order, with all its keys filled in. */
    public function all(): Collection
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $overrides = json_decode(Setting::get(self::SETTING), true) ?: [];

        return $this->services = collect(config('service_catalogue.services'))
            ->values()
            ->map(fn (array $service, int $index) => $this->normalise(
                array_merge($service, $overrides[$service['slug']] ?? []), $index
            ))
            ->sortBy('order')
            ->values();
    }

    public function visible(): Collection
    {
        return $this->all()->where('visible', true)->values();
    }

    public function find(?string $slug): ?array
    {
        return $this->all()->firstWhere('slug', $slug);
    }

    /** Visible services, or only those in one category. */
    public function inCategory(?string $category): Collection
    {
        $services = $this->visible();

        return isset($this->categories()[$category]) ? $services->where('category', $category)->values() : $services;
    }

    /** Services a person can actually use right now. */
    public function open(): Collection
    {
        return $this->visible()->where('open', true)->values();
    }

    /** Open application services: the ones a citizen can apply for. */
    public function applicable(): Collection
    {
        return $this->open()->where('kind', 'application')->values();
    }

    public function featured(): Collection
    {
        return $this->visible()->where('featured', true)->values();
    }

    public function canApply(array $service): bool
    {
        return $service['visible'] && $service['open'] && $service['kind'] === 'application' && $service['documents'] !== [];
    }

    /** Categories that hold at least one open service, each with those services. */
    public function openGroups(): Collection
    {
        $open = $this->open();

        return collect($this->categories())
            ->map(fn (array $category, string $key) => $category + ['key' => $key, 'services' => $open->where('category', $key)->values()])
            ->filter(fn (array $category) => $category['services']->isNotEmpty());
    }

    public function categories(): array
    {
        return config('service_catalogue.categories');
    }

    public function statuses(): array
    {
        return config('service_catalogue.statuses');
    }

    /** Store only what differs from the defaults, so config improvements still reach unedited values. */
    public function update(string $slug, array $values): array
    {
        $default = collect(config('service_catalogue.services'))->firstWhere('slug', $slug);
        $overrides = json_decode(Setting::get(self::SETTING), true) ?: [];

        $changed = array_keys(array_filter($values, fn ($value, $key) => ($default[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        $overrides[$slug] = array_intersect_key($values, array_flip($changed));

        Setting::put(self::SETTING, json_encode($overrides, JSON_UNESCAPED_UNICODE));
        $this->services = null;

        return $changed;
    }

    public function reset(string $slug): void
    {
        $overrides = json_decode(Setting::get(self::SETTING), true) ?: [];
        unset($overrides[$slug]);

        Setting::put(self::SETTING, json_encode($overrides, JSON_UNESCAPED_UNICODE));
        $this->services = null;
    }

    public function isEdited(string $slug): bool
    {
        return isset((json_decode(Setting::get(self::SETTING), true) ?: [])[$slug]);
    }

    private function normalise(array $service, int $index): array
    {
        $service += [
            'description'           => $service['summary'],
            'instructions'          => '',
            'visible'               => true,
            'featured'              => false,
            'order'                 => ($index + 1) * 10,
            'destination'           => null,
            'document_type'         => null,
            'fields'                => [],
            'documents'             => [],
            'requirements_verified' => false,
        ];

        $service['documents'] = array_map(fn (array $document) => $document + [
            'formats' => config('service_catalogue.formats'),
            'max_kb'  => config('service_catalogue.max_kb'),
        ], $service['documents']);

        // A destination whose route has gone must never appear as a working link.
        $reachable = $service['kind'] === 'application' ? $service['documents'] !== [] : Route::has((string) $service['destination']);
        $service['open'] = $reachable && (config("service_catalogue.statuses.{$service['status']}.open") ?? false);

        return $service;
    }
}
