<?php

namespace App\Services\Industry;

/**
 * Phase 11 Task 1 — read-only view of config/industries.php. The only place that knows the
 * registry's shape; everything else asks it.
 */
class IndustryRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function industries(): array
    {
        return (array) config('industries.industries', []);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->industries());
    }

    public function exists(string $industry): bool
    {
        return array_key_exists($industry, $this->industries());
    }

    /** @return array<string, mixed>|null */
    public function find(string $industry): ?array
    {
        return $this->industries()[$industry] ?? null;
    }

    public function capability(string $industry): ?string
    {
        return $this->find($industry)['capability'] ?? null;
    }

    /** @return array<string, string> sub-type key => label (empty = the industry has no sub-types) */
    public function verticals(string $industry): array
    {
        return (array) ($this->find($industry)['verticals'] ?? []);
    }

    /** @return array<string, mixed> configuration of one vertical (e.g. education.school → group_kind). Empty when none. */
    public function verticalConfig(string $industry, ?string $subtype): array
    {
        return $subtype === null ? [] : (array) ($this->find($industry)['vertical_config'][$subtype] ?? []);
    }

    /** A sub-type is valid when the industry has none and it is null, or it is one of the industry's own. */
    public function validSubtype(string $industry, ?string $subtype): bool
    {
        if ($subtype === null || $subtype === '') {
            return true;
        }

        return array_key_exists($subtype, $this->verticals($industry));
    }

    /** @return array<string, array<string, mixed>> module key => definition */
    public function modules(string $industry): array
    {
        return (array) ($this->find($industry)['modules'] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function module(string $industry, string $module): ?array
    {
        return $this->modules($industry)[$module] ?? null;
    }

    public function viewPermission(): string
    {
        return (string) config('industries.view_permission', 'view-industry-modules');
    }

    public function accountModule(): string
    {
        return (string) config('industries.module', 'industry_modules');
    }

    /** The registry as the API shows it (no internals the UI does not need). */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->industries() as $key => $industry) {
            $out[] = [
                'key' => $key,
                'label' => $industry['label'],
                'capability' => $industry['capability'],
                'verticals' => collect($industry['verticals'] ?? [])->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values()->all(),
                'modules' => collect($industry['modules'] ?? [])->map(fn ($m, $k) => [
                    'key' => $k, 'label' => $m['label'], 'crm_anchor' => $m['crm_anchor'] ?? null, 'available' => (bool) ($m['available'] ?? false),
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
