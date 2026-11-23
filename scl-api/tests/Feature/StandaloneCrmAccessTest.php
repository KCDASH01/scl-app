<?php

namespace Tests\Feature;

use App\Models\Lead;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StandaloneCrmAccessTest extends TestCase
{
    public function test_employee_cannot_manage_team(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'name'              => 'Manager',
            'email'             => 'manager@acme.test',
            'password'          => 'password',
            'role'              => 'manager',
            'branch_id'         => $branch->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
        $employee = $this->createUser([
            'name'              => 'Rep',
            'email'             => 'rep@acme.test',
            'password'          => 'password',
            'role'              => 'employee',
            'branch_id'         => $branch->id,
            'manager_id'        => $manager->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($employee);

        $this->postJson('/api/employees', [
                'name' => 'Other',
                'email' => 'other@acme.test',
                'password' => 'password123',
                'role' => 'employee',
            ])
            ->assertForbidden();
    }

    public function test_manager_can_create_team_member(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'name'              => 'Manager',
            'email'             => 'manager@acme.test',
            'password'          => 'password',
            'role'              => 'manager',
            'branch_id'         => $branch->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($manager);

        $this->postJson('/api/employees', [
                'name' => 'Rep',
                'email' => 'rep2@acme.test',
                'password' => 'password123',
                'role' => 'employee',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.manager_id', $manager->id);
    }

    public function test_superadmin_can_create_manager(): void
    {
        $owner = $this->createCompany();
        $branch = $this->createBranch();

        Sanctum::actingAs($owner);

        $this->postJson('/api/employees', [
                'name' => 'Mgr',
                'email' => 'mgr@acme.test',
                'password' => 'password123',
                'role' => 'manager',
                'branch_id' => $branch->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.branch_id', $branch->id);
    }

    public function test_crm_routes_are_available_without_a_paid_plan(): void
    {
        $owner = $this->createCompany();

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/leads')->assertOk();
    }

    public function test_inactive_company_is_blocked_from_crm_routes(): void
    {
        $owner = $this->createCompany(['is_active' => false]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'This CRM is currently inactive.');
    }
}
