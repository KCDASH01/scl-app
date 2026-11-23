<?php

namespace App\Http\Resources;

use App\Models\CompanySettings;
use App\Services\SidebarSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'avatar'       => $this->avatar,
            'role'         => $this->role,
            'staff_role_id'=> $this->staff_role_id,
            'staff_role'   => $this->whenLoaded('staffRole', fn () => $this->staffRole ? [
                'id'     => $this->staffRole->id,
                'name'   => $this->staffRole->name,
                'access' => $this->staffRole->access,
            ] : null),
            'is_active'    => $this->is_active,
            'has_password' => ! is_null($this->password),
            'branch_id'    => $this->branch_id,
            'manager_id'   => $this->manager_id,
            'branch'       => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'is_active' => $this->branch->is_active,
            ] : null),
            'manager'      => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id'    => $this->manager->id,
                'name'  => $this->manager->name,
                'email' => $this->manager->email,
            ] : null),
            'organization' => new OrganizationResource(CompanySettings::current()),
            'sidebar'      => app(SidebarSettingsService::class)->resolvedFor($this->resource),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
