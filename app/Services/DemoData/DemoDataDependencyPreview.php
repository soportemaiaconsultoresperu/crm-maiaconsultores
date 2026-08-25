<?php

namespace App\Services\DemoData;

class DemoDataDependencyPreview
{
    public const ALL_MODULES = [
        'users',
        'products',
        'leads',
        'customers',
        'contacts',
        'opportunities',
        'activities',
        'quotations',
        'documents',
        'campaigns',
        'support',
    ];

    /** @var array<string, list<string>> */
    private array $dependencies = [
        'contacts' => ['users', 'customers'],
        'opportunities' => ['users', 'leads', 'customers', 'contacts'],
        'activities' => ['users', 'leads', 'customers', 'opportunities'],
        'quotations' => ['users', 'products', 'customers', 'contacts', 'opportunities'],
        'documents' => ['users', 'leads', 'customers', 'opportunities', 'quotations'],
        'campaigns' => ['users', 'leads', 'customers', 'contacts', 'opportunities'],
        'support' => ['users', 'customers', 'contacts'],
    ];

    /**
     * @param list<string> $requested
     * @return array{requested:list<string>,expanded:list<string>,dependencies:list<string>}
     */
    public function preview(array $requested): array
    {
        $requested = $this->normalize($requested);
        $expanded = $requested;

        do {
            $changed = false;
            foreach ($expanded as $module) {
                foreach ($this->dependencies[$module] ?? [] as $dependency) {
                    if (! in_array($dependency, $expanded, true)) {
                        $expanded[] = $dependency;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        $expanded = $this->sort($expanded);

        return [
            'requested' => $requested,
            'expanded' => $expanded,
            'dependencies' => array_values(array_diff($expanded, $requested)),
        ];
    }

    /** @param list<string> $modules @return list<string> */
    public function normalize(array $modules): array
    {
        $modules = array_values(array_unique(array_filter($modules, fn ($module): bool => in_array($module, self::ALL_MODULES, true))));

        return $this->sort($modules ?: self::ALL_MODULES);
    }

    /** @param list<string> $modules @return list<string> */
    private function sort(array $modules): array
    {
        return array_values(array_filter(self::ALL_MODULES, fn (string $module): bool => in_array($module, $modules, true)));
    }
}
