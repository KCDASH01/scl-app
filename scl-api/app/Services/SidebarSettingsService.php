<?php

namespace App\Services;

use App\Models\CompanySettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SidebarSettingsService
{
    public const WORKSPACE_KEYS = [
        '/leads',
        '/admissions',
        '/attendance',
        '/hostel-expenses',
        '/follow-ups',
        '/meetings',
        '/important',
        '/pipelines',
        '/deals',
        '/hostel-fees',
        '/activities',
        '/calendar',
        '/reports',
        '/targets',
    ];

    public const MANAGER_ONLY_KEYS = [
        '/team',
        '/departments',
        '/batch-years',
        '/staff-attendance',
    ];

    public const ALWAYS_VISIBLE = [
        '/dashboard',
        '/settings',
    ];

    public const LEAD_EDIT_KEY = '/leads/edit';

    /**
     * @return list<string>
     */
    public function keysForRole(string $role): array
    {
        $keys = self::WORKSPACE_KEYS;

        if (in_array($role, ['manager', 'admin'], true)) {
            $keys = array_merge($keys, self::MANAGER_ONLY_KEYS);
        }

        if ($role === 'employee') {
            $keys[] = self::LEAD_EDIT_KEY;
        }

        return $keys;
    }

    /**
     * @return array{manager: array<string, bool>, employee: array<string, bool>}
     */
    public function forBranch(?int $branchId): array
    {
        $sidebar = $this->rawSidebar();
        if ($branchId) {
            $entry = $this->branchEntry($sidebar, $branchId);
            if ($entry) {
                return $this->pair($entry);
            }
        }

        return $this->pair($sidebar);
    }

    /**
     * @param  array<string, mixed>  $manager
     * @param  array<string, mixed>  $employee
     * @return array{manager: array<string, bool>, employee: array<string, bool>}
     */
    public function update(int $branchId, array $manager, array $employee): array
    {
        return DB::transaction(function () use ($branchId, $manager, $employee) {
            $company = CompanySettings::current();
            $company = CompanySettings::query()->lockForUpdate()->findOrFail($company->id);
            $settings = $company->settings ?? [];
            $sidebar = is_array($settings['sidebar'] ?? null) ? $settings['sidebar'] : [];
            $branches = is_array($sidebar['branches'] ?? null) ? $sidebar['branches'] : [];
            $saved = $this->pair([
                'manager' => $manager,
                'employee' => $employee,
            ]);
            $branches[(string) $branchId] = $saved;
            $sidebar['branches'] = $branches;
            $settings['sidebar'] = $sidebar;
            $company->update(['settings' => $settings]);

            return $saved;
        });
    }

    public function roleFor(User $user): string
    {
        return in_array($user->role, ['manager', 'admin'], true) ? 'manager' : 'employee';
    }

    public function overrideFor(User $user): ?array
    {
        $entry = $this->overridesForScope((int) $user->branch_id, $this->roleFor($user))[(string) $user->id] ?? null;

        return is_array($entry) ? $entry : null;
    }

    public function overridesForScope(int $branchId, string $role): array
    {
        return $this->rawSidebar()['user_overrides'][(string) $branchId][$role] ?? [];
    }

    public function mapFor(User $user): array
    {
        $role = $this->roleFor($user);
        $defaults = $this->forBranch($user->branch_id ? (int) $user->branch_id : null)[$role];
        $override = array_intersect_key($this->overrideFor($user) ?? [], $defaults);

        return array_merge($defaults, $override);
    }

    public function saveScope(int $branchId, string $role, ?User $user, array $map, bool $reset = false, bool $clear = false): void
    {
        DB::transaction(function () use ($branchId, $role, $user, $map, $reset, $clear) {
            $current = CompanySettings::current();
            $company = CompanySettings::query()->lockForUpdate()->findOrFail($current->id);
            $settings = $company->settings ?? [];
            $sidebar = $settings['sidebar'] ?? [];
            $branchKey = (string) $branchId;
            if ($user) {
                if ($reset) {
                    unset($sidebar['user_overrides'][$branchKey][$role][(string) $user->id]);
                } else {
                    // Save only supported keys; missing keys continue to inherit defaults.
                    $sidebar['user_overrides'][$branchKey][$role][(string) $user->id] = array_map(
                        fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                        array_intersect_key($map, array_flip($this->keysForRole($role)))
                    );
                }
            } else {
                $pair = $this->forBranch($branchId);
                $pair[$role] = $this->normalizeMap($map, $this->keysForRole($role));
                $sidebar['branches'][$branchKey] = $pair;
                if ($clear) {
                    unset($sidebar['user_overrides'][$branchKey][$role]);
                }
            }
            $settings['sidebar'] = $sidebar;
            $company->update(['settings' => $settings]);
        });
    }

    /**
     * @return array<string, bool>
     */
    public function resolvedFor(User $user): array
    {
        if ($user->isOwner()) {
            $keys = array_merge(
                self::ALWAYS_VISIBLE,
                ['/manage-sidebar'],
                self::WORKSPACE_KEYS,
                self::MANAGER_ONLY_KEYS,
                ['/branches', '/courses']
            );

            return $this->allTrue($keys);
        }

        $user->loadMissing('staffRole');
        if ($user->staffRole?->access === 'none') {
            return array_merge(
                $this->allTrue(self::ALWAYS_VISIBLE),
                $this->allFalse(array_merge(self::WORKSPACE_KEYS, self::MANAGER_ONLY_KEYS))
            );
        }

        $map = $this->mapFor($user);

        return array_merge($this->allTrue(self::ALWAYS_VISIBLE), $map);
    }

    public function employeeCanEditLeads(User $user): bool
    {
        if ($user->role !== 'employee') {
            return true;
        }

        return (bool) ($this->resolvedFor($user)[self::LEAD_EDIT_KEY] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawSidebar(): array
    {
        $settings = CompanySettings::current()->settings ?? [];

        return is_array($settings['sidebar'] ?? null) ? $settings['sidebar'] : [];
    }

    /**
     * @param  array<string, mixed>  $sidebar
     * @return array<string, mixed>|null
     */
    private function branchEntry(array $sidebar, int $branchId): ?array
    {
        $branches = $sidebar['branches'] ?? null;
        if (! is_array($branches)) {
            return null;
        }

        $entry = $branches[(string) $branchId] ?? $branches[$branchId] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{manager: array<string, bool>, employee: array<string, bool>}
     */
    private function pair(array $source): array
    {
        return [
            'manager' => $this->normalizeMap(is_array($source['manager'] ?? null) ? $source['manager'] : [], $this->keysForRole('manager')),
            'employee' => $this->normalizeMap(is_array($source['employee'] ?? null) ? $source['employee'] : [], $this->keysForRole('employee')),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     * @return array<string, bool>
     */
    private function normalizeMap(array $input, array $allowed): array
    {
        $map = [];

        foreach ($allowed as $href) {
            $map[$href] = array_key_exists($href, $input)
                ? filter_var($input[$href], FILTER_VALIDATE_BOOLEAN)
                : $href !== self::LEAD_EDIT_KEY;
        }

        return $map;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    private function allTrue(array $keys): array
    {
        return array_fill_keys($keys, true);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    private function allFalse(array $keys): array
    {
        return array_fill_keys($keys, false);
    }
}
