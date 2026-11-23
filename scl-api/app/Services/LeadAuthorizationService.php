<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;

/**
 * Centralized lead access rules for superadmin, admin, manager, and employees.
 */
class LeadAuthorizationService
{
    public function canCreate(User $user): bool
    {
        return in_array($user->role, User::ROLES, true);
    }

    public function canManageAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function canUpdate(User $user, Lead $lead): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->role === 'employee' && $lead->assigned_to === $user->id;
    }

    /**
     * Employees may change status unless lead editing itself is turned off.
     * Detail fields stay blocked while Manage Sidebar has Edit lead disabled.
     *
     * @param  array<string, mixed>  $validated
     */
    public function deniesDetailEdit(User $user, array $validated): bool
    {
        if ($user->role !== 'employee' || app(SidebarSettingsService::class)->employeeCanEditLeads($user)) {
            return false;
        }

        $detailFields = [
            'first_name', 'last_name', 'email', 'phone', 'course_id',
            'city', 'state', 'country', 'pin', 'source', 'pipeline_id', 'stage_id',
        ];

        return array_intersect($detailFields, array_keys($validated)) !== [];
    }

    public function canDelete(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Force employee ownership fields on create — never trust the client.
     */
    public function attributesForCreate(User $user, array $validated): array
    {
        if ($user->role !== 'employee') {
            return $validated;
        }

        return array_merge($validated, [
            'assigned_to' => $user->id,
            'created_by'  => $user->id,
        ]);
    }

    /**
     * Prevent employees from changing assignment or creator on update.
     */
    public function attributesForUpdate(User $user, array $validated): array
    {
        if ($user->role !== 'employee') {
            return $validated;
        }

        unset($validated['created_by']);
        $validated['assigned_to'] = $user->id;

        return $validated;
    }
}
