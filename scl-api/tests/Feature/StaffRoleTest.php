<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffRoleTest extends TestCase
{
    public function test_superadmin_can_add_roles_and_assign_them_to_members(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'name' => 'Branch Manager',
            'email' => 'branch.mgr@acme.test',
            'role' => 'manager',
            'branch_id' => $branch->id,
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($super);

        $teacher = $this->postJson('/api/staff-roles', [
            'name' => 'Teacher',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Teacher')
            ->assertJsonPath('data.access', 'none')
            ->assertJsonPath('data.is_system', false)
            ->json('data');

        $this->postJson('/api/staff-roles', [
            'name' => 'Teacher',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $system = StaffRole::query()->where('name', 'Employee')->firstOrFail();
        $this->putJson('/api/staff-roles/'.$system->id, ['name' => 'Staff'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
        $this->deleteJson('/api/staff-roles/'.$system->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/employees', [
            'name' => 'Ada',
            'email' => 'ada@acme.test',
            'password' => 'password123',
            'staff_role_id' => $teacher['id'],
            'branch_id' => $branch->id,
            'manager_id' => $manager->id,
        ])->assertCreated()
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.staff_role.name', 'Teacher');

        $ada = User::query()->where('email', 'ada@acme.test')->firstOrFail();
        Sanctum::actingAs($ada);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.sidebar./dashboard', true)
            ->assertJsonPath('data.sidebar./leads', false)
            ->assertJsonPath('data.sidebar./attendance', false)
            ->assertJsonPath('data.sidebar./team', false);

        Sanctum::actingAs($super);
        $listed = collect($this->getJson('/api/employees?staff_role_id='.$teacher['id'])->assertOk()->json('data'));
        $this->assertTrue($listed->pluck('email')->contains('ada@acme.test'));

        $this->deleteJson('/api/staff-roles/'.$teacher['id'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_manager_can_assign_only_employee_roles(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'name' => 'Branch Manager',
            'email' => 'mgr.roles@acme.test',
            'role' => 'manager',
            'branch_id' => $branch->id,
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $clerk = StaffRole::create(['name' => 'Clerk', 'access' => 'employee']);
        $principal = StaffRole::create(['name' => 'Principal', 'access' => 'manager']);

        Sanctum::actingAs($manager);

        $this->postJson('/api/staff-roles', [
            'name' => 'Blocked',
        ])->assertForbidden();

        $this->getJson('/api/staff-roles')->assertOk()->assertJsonFragment(['name' => 'Clerk']);

        $this->postJson('/api/employees', [
            'name' => 'Cara',
            'email' => 'cara@acme.test',
            'password' => 'password123',
            'staff_role_id' => $principal->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['staff_role_id']);

        $this->postJson('/api/employees', [
            'name' => 'Cara',
            'email' => 'cara@acme.test',
            'password' => 'password123',
            'staff_role_id' => $clerk->id,
        ])->assertCreated()
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.staff_role.name', 'Clerk')
            ->assertJsonPath('data.manager_id', $manager->id);

        $this->assertSame('employee', User::query()->where('email', 'cara@acme.test')->value('role'));
    }
}
