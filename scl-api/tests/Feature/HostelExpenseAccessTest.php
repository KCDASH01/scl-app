<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\Hostel;
use App\Models\HostelExpense;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HostelExpenseAccessTest extends TestCase
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

        return compact('super', 'east', 'west', 'eastMgr', 'westMgr', 'eastEmp');
    }

    public function test_manager_can_crud_hostel_and_category_in_own_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastMgr']);

        $hostel = $this->postJson('/api/hostels', [
            'name' => 'East Boys', 'code' => 'EB1', 'capacity' => 40,
        ])->assertCreated()->json('data');
        $this->assertSame($data['east']->id, $hostel['branch_id']);

        $type = $this->postJson('/api/expense-categories', [
            'name' => 'Food',
        ])->assertCreated()->json('data');
        $this->assertSame($data['east']->id, $type['branch_id']);

        $this->putJson('/api/hostels/'.$hostel['id'], [
            'name' => 'East Boys Hostel', 'code' => 'EB1',
        ])->assertOk()->assertJsonPath('data.name', 'East Boys Hostel');

        $this->putJson('/api/expense-categories/'.$type['id'], [
            'name' => 'Meals',
        ])->assertOk()->assertJsonPath('data.name', 'Meals');

        $this->postJson('/api/hostels', [
            'branch_id' => 0, 'name' => 'East Girls', 'code' => 'EG1',
        ])->assertCreated()->assertJsonPath('data.branch_id', $data['east']->id);
    }

    public function test_employee_cannot_access_hostel_expenses(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastEmp']);

        $this->getJson('/api/hostels')->assertForbidden();
        $this->getJson('/api/expense-categories')->assertForbidden();
        $this->getJson('/api/hostel-expenses')->assertForbidden();
        $this->postJson('/api/hostels', ['name' => 'X', 'code' => 'X1'])->assertForbidden();
    }

    public function test_manager_cannot_see_other_branch_records(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastMgr']);
        $eastHostel = $this->postJson('/api/hostels', ['name' => 'East H', 'code' => 'EH'])->assertCreated()->json('data');
        $eastType = $this->postJson('/api/expense-categories', ['name' => 'Power'])->assertCreated()->json('data');
        $expense = $this->postJson('/api/hostel-expenses', [
            'hostel_id' => $eastHostel['id'],
            'expense_category_id' => $eastType['id'],
            'amount' => 1500,
            'expense_date' => now()->toDateString(),
            'payment_mode' => 'cash',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($data['westMgr']);
        $this->assertFalse(collect($this->getJson('/api/hostels')->assertOk()->json('data'))->pluck('id')->contains($eastHostel['id']));
        $this->getJson('/api/hostels/'.$eastHostel['id'])->assertNotFound();
        $this->getJson('/api/hostel-expenses/'.$expense['id'])->assertNotFound();
        $this->postJson('/api/hostel-expenses', [
            'hostel_id' => $eastHostel['id'],
            'expense_category_id' => $eastType['id'],
            'amount' => 100,
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_expense_category_must_match_hostel_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastHostel = Hostel::create([
            'branch_id' => $data['east']->id, 'name' => 'East H', 'code' => 'EH', 'is_active' => true,
        ]);
        $westType = ExpenseCategory::create([
            'branch_id' => $data['west']->id, 'name' => 'West Food', 'is_active' => true,
        ]);

        $this->postJson('/api/hostel-expenses', [
            'branch_id' => $data['east']->id,
            'hostel_id' => $eastHostel->id,
            'expense_category_id' => $westType->id,
            'amount' => 500,
            'expense_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_cannot_delete_hostel_or_type_with_expenses(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastMgr']);

        $hostel = $this->postJson('/api/hostels', ['name' => 'Keep', 'code' => 'KEEP'])->assertCreated()->json('data');
        $type = $this->postJson('/api/expense-categories', ['name' => 'Water'])->assertCreated()->json('data');
        $this->postJson('/api/hostel-expenses', [
            'hostel_id' => $hostel['id'],
            'expense_category_id' => $type['id'],
            'amount' => 200,
            'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $this->deleteJson('/api/hostels/'.$hostel['id'])->assertStatus(422);
        $this->deleteJson('/api/expense-categories/'.$type['id'])->assertStatus(422);
    }

    public function test_list_is_branch_scoped_and_export_returns_csv(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastHostel = Hostel::create([
            'branch_id' => $data['east']->id, 'name' => 'East H', 'code' => 'EH', 'is_active' => true,
        ]);
        $westHostel = Hostel::create([
            'branch_id' => $data['west']->id, 'name' => 'West H', 'code' => 'WH', 'is_active' => true,
        ]);
        $eastType = ExpenseCategory::create([
            'branch_id' => $data['east']->id, 'name' => 'Food', 'is_active' => true,
        ]);
        $westType = ExpenseCategory::create([
            'branch_id' => $data['west']->id, 'name' => 'Food', 'is_active' => true,
        ]);

        HostelExpense::create([
            'branch_id' => $data['east']->id, 'hostel_id' => $eastHostel->id,
            'expense_category_id' => $eastType->id, 'amount' => 1000,
            'expense_date' => now()->toDateString(), 'created_by' => $data['super']->id,
        ]);
        HostelExpense::create([
            'branch_id' => $data['west']->id, 'hostel_id' => $westHostel->id,
            'expense_category_id' => $westType->id, 'amount' => 2500,
            'expense_date' => now()->toDateString(), 'created_by' => $data['super']->id,
        ]);

        $eastList = $this->getJson('/api/hostel-expenses', ['X-Branch-Id' => (string) $data['east']->id])
            ->assertOk();
        $this->assertSame(1, $eastList->json('summary.count'));
        $this->assertEquals(1000, (float) $eastList->json('summary.total_amount'));
        $this->assertCount(1, $eastList->json('data'));

        $this->withHeaders(['X-Branch-Id' => (string) $data['east']->id])
            ->get('/api/hostel-expenses/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_inactive_hostel_cannot_be_used_for_new_expense(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastMgr']);

        $hostel = Hostel::create([
            'branch_id' => $data['east']->id, 'name' => 'Closed', 'code' => 'CL', 'is_active' => false,
        ]);
        $type = ExpenseCategory::create([
            'branch_id' => $data['east']->id, 'name' => 'Food', 'is_active' => true,
        ]);

        $this->postJson('/api/hostel-expenses', [
            'hostel_id' => $hostel->id,
            'expense_category_id' => $type->id,
            'amount' => 100,
            'expense_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_hostel_code_unique_within_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $this->postJson('/api/hostels', [
            'branch_id' => $data['east']->id, 'name' => 'A', 'code' => 'H1',
        ])->assertCreated();
        $this->postJson('/api/hostels', [
            'branch_id' => $data['east']->id, 'name' => 'B', 'code' => 'H1',
        ])->assertStatus(422);
        $this->postJson('/api/hostels', [
            'branch_id' => $data['west']->id, 'name' => 'A', 'code' => 'H1',
        ])->assertCreated();
    }
}
