<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    private function seedHierarchy(): array
    {
        $super = $this->createCompany();
        $east = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $west = $this->createBranch(['name' => 'West', 'code' => 'WEST']);

        $eastMgr = $this->createUser([
            'name' => 'East Manager', 'email' => 'east.mgr@acme.test', 'role' => 'manager',
            'branch_id' => $east->id, 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $westMgr = $this->createUser([
            'name' => 'West Manager', 'email' => 'west.mgr@acme.test', 'role' => 'manager',
            'branch_id' => $west->id, 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $eastEmp = $this->createUser([
            'name' => 'East Emp', 'email' => 'east.emp@acme.test', 'role' => 'employee',
            'branch_id' => $east->id, 'manager_id' => $eastMgr->id, 'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $westEmp = $this->createUser([
            'name' => 'West Emp', 'email' => 'west.emp@acme.test', 'role' => 'employee',
            'branch_id' => $west->id, 'manager_id' => $westMgr->id, 'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $eastLead = $this->createLead([
            'branch_id' => $east->id, 'created_by' => $eastMgr->id, 'assigned_to' => $eastEmp->id,
            'first_name' => 'East', 'last_name' => 'Lead', 'status' => 'new',
            'lead_date' => now()->toDateString(),
        ]);
        $westLead = $this->createLead([
            'branch_id' => $west->id, 'created_by' => $westMgr->id, 'assigned_to' => $westEmp->id,
            'first_name' => 'West', 'last_name' => 'Lead', 'status' => 'new',
            'lead_date' => now()->toDateString(),
        ]);

        return compact('super', 'east', 'west', 'eastMgr', 'westMgr', 'eastEmp', 'westEmp', 'eastLead', 'westLead');
    }

    public function test_employee_requires_manager_and_branch(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        Sanctum::actingAs($super);

        $this->postJson('/api/employees', [
            'name' => 'No Mgr', 'email' => 'nomgr@acme.test', 'password' => 'password123',
            'role' => 'employee', 'branch_id' => $branch->id,
        ])->assertStatus(422);

        $this->postJson('/api/employees', [
            'name' => 'No Branch', 'email' => 'nobr@acme.test', 'password' => 'password123',
            'role' => 'manager',
        ])->assertStatus(422);
    }

    public function test_superadmin_all_listed_branches_ignores_header_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $all = collect($this->getJson('/api/employees?branch_id=all', [
            'X-Branch-Id' => (string) $data['east']->id,
        ])->assertOk()->json('data'))->pluck('email');

        $this->assertTrue($all->contains('east.emp@acme.test'));
        $this->assertTrue($all->contains('west.emp@acme.test'));
        $this->assertTrue($all->contains('west.mgr@acme.test'));

        $eastOnly = collect($this->getJson('/api/employees', [
            'X-Branch-Id' => (string) $data['east']->id,
        ])->assertOk()->json('data'))->pluck('email');

        $this->assertTrue($eastOnly->contains('east.emp@acme.test'));
        $this->assertFalse($eastOnly->contains('west.emp@acme.test'));
    }

    public function test_manager_list_is_branch_scoped(): void
    {
        ['east' => $east, 'west' => $west, 'super' => $super] = $this->seedHierarchy();
        Sanctum::actingAs($super);

        $eastManagers = $this->getJson("/api/branches/{$east->id}/managers")->assertOk()->json('data');
        $this->assertCount(1, $eastManagers);
        $this->assertSame('east.mgr@acme.test', $eastManagers[0]['email']);

        $westManagers = $this->getJson("/api/branches/{$west->id}/managers")->assertOk()->json('data');
        $this->assertSame('west.mgr@acme.test', $westManagers[0]['email']);
    }

    public function test_manager_sees_branch_leads_but_not_other_branch(): void
    {
        ['eastMgr' => $eastMgr, 'eastLead' => $eastLead, 'westLead' => $westLead] = $this->seedHierarchy();
        Sanctum::actingAs($eastMgr);

        $ids = collect($this->getJson('/api/leads')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($eastLead->id));
        $this->assertFalse($ids->contains($westLead->id));

        $this->getJson('/api/leads/'.$westLead->id)->assertNotFound();
    }

    public function test_employee_cannot_open_unassigned_deal_in_own_branch(): void
    {
        $data = $this->seedHierarchy();
        $pipeline = app(\App\Services\PipelineSetupService::class)->createDefaultPipeline($data['east']->id);
        $stage = $pipeline->stages()->first();

        $deal = $this->createDeal([
            'branch_id'    => $data['east']->id,
            'title'        => 'Mgr Deal',
            'pipeline_id'  => $pipeline->id,
            'stage_id'     => $stage->id,
            'status'       => 'open',
            'assigned_to'  => $data['eastMgr']->id,
            'created_by'   => $data['eastMgr']->id,
        ]);

        Sanctum::actingAs($data['eastEmp']);
        $this->getJson('/api/deals/'.$deal->id)->assertForbidden();
    }

    public function test_employee_only_sees_assigned_leads_in_branch(): void
    {
        $data = $this->seedHierarchy();
        $otherEast = $this->createLead([
            'branch_id' => $data['east']->id, 'created_by' => $data['eastMgr']->id,
            'assigned_to' => $data['eastMgr']->id, 'first_name' => 'Other',
            'status' => 'new', 'lead_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($data['eastEmp']);
        $ids = collect($this->getJson('/api/leads')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($data['eastLead']->id));
        $this->assertFalse($ids->contains($otherEast->id));
        $this->assertFalse($ids->contains($data['westLead']->id));
    }

    public function test_manager_cannot_manage_other_managers_employees(): void
    {
        ['eastMgr' => $eastMgr, 'westEmp' => $westEmp] = $this->seedHierarchy();
        Sanctum::actingAs($eastMgr);

        $this->putJson('/api/employees/'.$westEmp->id, ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson('/api/employees/'.$westEmp->id)->assertForbidden();
    }

    public function test_manager_cannot_create_manager_or_branch(): void
    {
        ['eastMgr' => $eastMgr, 'east' => $east] = $this->seedHierarchy();
        Sanctum::actingAs($eastMgr);

        $this->postJson('/api/branches', [
            'name' => 'Rogue', 'code' => 'ROGUE',
        ])->assertForbidden();

        $this->postJson('/api/employees', [
            'name' => 'New Mgr', 'email' => 'newmgr@acme.test', 'password' => 'password123',
            'role' => 'manager', 'branch_id' => $east->id,
        ])->assertStatus(422);

        $this->postJson('/api/employees', [
            'name' => 'New Emp', 'email' => 'newemp@acme.test', 'password' => 'password123',
            'role' => 'employee',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.branch_id', $east->id)
            ->assertJsonPath('data.manager_id', $eastMgr->id);
    }

    public function test_cross_branch_lead_assignment_is_rejected(): void
    {
        ['super' => $super, 'east' => $east, 'westEmp' => $westEmp] = $this->seedHierarchy();
        Sanctum::actingAs($super);

        $course = $this->createCourse(['branch_id' => $east->id, 'code' => 'EASTLEAD']);

        $this->postJson('/api/leads', [
            'first_name' => 'X', 'branch_id' => $east->id,
            'course_id' => $course->id,
            'assigned_to' => $westEmp->id,
        ])->assertStatus(422);
    }

    public function test_superadmin_all_branches_and_selected_branch(): void
    {
        ['super' => $super, 'eastLead' => $eastLead, 'westLead' => $westLead, 'east' => $east] = $this->seedHierarchy();
        Sanctum::actingAs($super);

        $all = collect($this->getJson('/api/leads')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($all->contains($eastLead->id));
        $this->assertTrue($all->contains($westLead->id));

        $eastOnly = collect($this->withHeaders(['X-Branch-Id' => (string) $east->id])->getJson('/api/leads')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($eastOnly->contains($eastLead->id));
        $this->assertFalse($eastOnly->contains($westLead->id));
    }

    public function test_superadmin_create_requires_specific_branch(): void
    {
        $super = $this->createCompany();
        Sanctum::actingAs($super);

        $this->postJson('/api/leads', [
            'first_name' => 'X',
        ])->assertStatus(422);
    }

    public function test_inactive_branch_blocks_manager_session(): void
    {
        ['eastMgr' => $eastMgr, 'east' => $east, 'super' => $super] = $this->seedHierarchy();
        $east->update(['is_active' => false]);

        Sanctum::actingAs($eastMgr);
        $this->getJson('/api/leads')
            ->assertForbidden()
            ->assertJsonPath('message', 'This branch is currently inactive.');

        Sanctum::actingAs($super);
        $this->getJson('/api/leads')->assertOk();
        $this->patchJson('/api/branches/'.$east->id.'/status')->assertOk();
    }

    public function test_cannot_delete_branch_with_users(): void
    {
        ['super' => $super, 'east' => $east] = $this->seedHierarchy();
        Sanctum::actingAs($super);

        $this->deleteJson('/api/branches/'.$east->id)->assertStatus(422);
    }

    public function test_saas_endpoints_remain_removed(): void
    {
        $super = $this->createCompany();
        Sanctum::actingAs($super);
        $this->getJson('/api/subscription')->assertNotFound();
        $this->postJson('/api/auth/register', [])->assertNotFound();
    }
}
