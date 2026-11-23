<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptSettingsTest extends TestCase
{
    public function test_branch_without_its_own_details_uses_the_shared_receipt(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        CompanySettings::current()->update([
            'settings' => [
                'receipt' => ['company_name' => 'Legacy College', 'city' => 'Kendrapara'],
                'sidebar' => ['manager' => ['/leads' => false]],
            ],
        ]);
        Sanctum::actingAs($super);

        $this->getJson('/api/organization/receipt-settings?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.company_name', 'Legacy College')
            ->assertJsonPath('data.city', 'Kendrapara');
    }

    public function test_saving_one_branch_does_not_change_another(): void
    {
        $super = $this->createCompany();
        $east = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $west = $this->createBranch(['name' => 'West', 'code' => 'WEST']);
        CompanySettings::current()->update([
            'settings' => [
                'receipt' => ['company_name' => 'Legacy College', 'footer_note' => 'THANK YOU'],
                'sidebar' => ['manager' => ['/leads' => false]],
            ],
        ]);
        Sanctum::actingAs($super);

        $this->putJson('/api/organization/receipt-settings', [
            'branch_id'    => $east->id,
            'company_name' => 'East Campus',
            'city'         => 'Cuttack',
            'footer_note'  => 'East thanks you',
        ])->assertOk()
            ->assertJsonPath('data.branch_id', $east->id)
            ->assertJsonPath('data.company_name', 'East Campus');

        $this->getJson('/api/organization/receipt-settings?branch_id='.$west->id)
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Legacy College')
            ->assertJsonPath('data.footer_note', 'THANK YOU');

        $this->getJson('/api/organization/receipt-settings?branch_id='.$east->id)
            ->assertOk()
            ->assertJsonPath('data.company_name', 'East Campus')
            ->assertJsonPath('data.city', 'Cuttack');

        $settings = CompanySettings::current()->fresh()->settings;
        $this->assertSame('Legacy College', $settings['receipt']['company_name']);
        $this->assertSame('East Campus', $settings['receipt_branches'][(string) $east->id]['company_name']);
        $this->assertFalse($settings['sidebar']['manager']['/leads']);
        $this->assertArrayNotHasKey((string) $west->id, $settings['receipt_branches']);
    }

    public function test_manager_can_update_only_their_branch(): void
    {
        $this->createCompany();
        $east = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $west = $this->createBranch(['name' => 'West', 'code' => 'WEST']);
        $manager = $this->createUser([
            'name'      => 'East Manager',
            'email'     => 'east-mgr@acme.test',
            'role'      => 'manager',
            'branch_id' => $east->id,
        ]);
        Sanctum::actingAs($manager);

        $this->putJson('/api/organization/receipt-settings', [
            'branch_id'    => $west->id,
            'company_name' => 'Should Fail',
        ])->assertForbidden();

        $this->putJson('/api/organization/receipt-settings', [
            'branch_id'    => $east->id,
            'company_name' => 'East Office',
        ])->assertOk()
            ->assertJsonPath('data.company_name', 'East Office');

        $this->getJson('/api/organization/receipt-settings')
            ->assertOk()
            ->assertJsonPath('data.branch_id', $east->id)
            ->assertJsonPath('data.company_name', 'East Office');
    }

    public function test_employee_can_read_own_branch_but_not_edit(): void
    {
        $this->createCompany();
        $east = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $west = $this->createBranch(['name' => 'West', 'code' => 'WEST']);
        $employee = $this->createUser([
            'name'      => 'East Employee',
            'email'     => 'east-emp@acme.test',
            'role'      => 'employee',
            'branch_id' => $east->id,
        ]);
        Sanctum::actingAs($employee);

        $this->getJson('/api/organization/receipt-settings?branch_id='.$west->id)
            ->assertForbidden();

        $this->getJson('/api/organization/receipt-settings?branch_id='.$east->id)
            ->assertOk()
            ->assertJsonPath('data.branch_id', $east->id);

        $this->putJson('/api/organization/receipt-settings', [
            'branch_id'    => $east->id,
            'company_name' => 'Nope',
        ])->assertForbidden();
    }

    public function test_superadmin_must_choose_a_branch(): void
    {
        $super = $this->createCompany();
        Sanctum::actingAs($super);

        $this->getJson('/api/organization/receipt-settings')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
    }
}
