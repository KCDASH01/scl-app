<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Services\SidebarSettingsService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SidebarSettingsTest extends TestCase
{
    public function test_get_returns_defaults_when_unset(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        Sanctum::actingAs($super);

        $this->getJson('/api/sidebar-settings?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.manager./leads', true)
            ->assertJsonPath('data.manager./reports', true)
            ->assertJsonPath('data.manager./team', true)
            ->assertJsonPath('data.employee./leads', true)
            ->assertJsonPath('data.employee./leads/edit', false)
            ->assertJsonPath('data.employee./reports', true)
            ->assertJsonMissingPath('data.manager./leads/edit')
            ->assertJsonMissingPath('data.employee./team')
            ->assertJsonPath('data.resolved./dashboard', true)
            ->assertJsonPath('data.resolved./manage-sidebar', true);
    }

    public function test_superadmin_can_persist_flags(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $other = $this->createBranch(['name' => 'West', 'code' => 'WEST']);
        Sanctum::actingAs($super);

        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $branch->id,
            'manager' => [
                '/leads'   => true,
                '/reports' => false,
                '/team'    => false,
            ],
            'employee' => [
                '/leads'   => false,
                '/reports' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.manager./leads', true)
            ->assertJsonPath('data.manager./reports', false)
            ->assertJsonPath('data.manager./team', false)
            ->assertJsonPath('data.manager./deals', true)
            ->assertJsonPath('data.employee./leads', false)
            ->assertJsonPath('data.employee./reports', true)
            ->assertJsonPath('data.employee./deals', true);

        $stored = CompanySettings::current()->settings['sidebar']['branches'][(string) $branch->id];
        $this->assertFalse($stored['manager']['/reports']);
        $this->assertFalse($stored['employee']['/leads']);

        $this->getJson('/api/sidebar-settings?branch_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.manager./reports', true)
            ->assertJsonPath('data.employee./leads', true);
    }

    public function test_put_ignores_unknown_hrefs(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        Sanctum::actingAs($super);

        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $branch->id,
            'manager'  => ['/leads' => false, '/not-a-page' => false, '/branches' => false],
            'employee' => ['/leads' => true, '/team' => false],
        ])->assertOk()
            ->assertJsonMissingPath('data.manager./not-a-page')
            ->assertJsonMissingPath('data.manager./branches')
            ->assertJsonMissingPath('data.employee./team')
            ->assertJsonPath('data.manager./leads', false);
    }

    public function test_manager_cannot_update_sidebar_settings(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'email' => 'mgr@acme.test', 'role' => 'manager', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($manager);

        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $branch->id,
            'manager'  => ['/leads' => false],
            'employee' => ['/leads' => false],
        ])->assertForbidden();
    }

    public function test_employee_cannot_update_sidebar_settings(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $employee = $this->createUser([
            'email' => 'emp@acme.test', 'role' => 'employee', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($employee);

        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $branch->id,
            'manager'  => ['/leads' => false],
            'employee' => ['/leads' => false],
        ])->assertForbidden();
    }

    public function test_me_includes_resolved_map_for_manager(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'email' => 'mgr@acme.test', 'role' => 'manager', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($super);
        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $branch->id,
            'manager'  => ['/reports' => false, '/team' => false],
            'employee' => ['/leads' => false],
        ])->assertOk();

        Sanctum::actingAs($manager);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.sidebar./reports', false)
            ->assertJsonPath('data.sidebar./team', false)
            ->assertJsonPath('data.sidebar./leads', true)
            ->assertJsonPath('data.sidebar./dashboard', true);
    }

    public function test_me_includes_resolved_map_for_employee(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $employee = $this->createUser([
            'email' => 'emp@acme.test', 'role' => 'employee', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);

        app(SidebarSettingsService::class)->update(
            $branch->id,
            ['/reports' => false],
            ['/leads' => false, '/attendance' => false]
        );

        Sanctum::actingAs($employee);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.sidebar./leads', false)
            ->assertJsonPath('data.sidebar./attendance', false)
            ->assertJsonPath('data.sidebar./reports', true)
            ->assertJsonPath('data.sidebar./dashboard', true);
    }

    public function test_superadmin_me_keeps_all_items_visible(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        app(SidebarSettingsService::class)->update(
            $branch->id,
            ['/reports' => false],
            ['/leads' => false]
        );

        Sanctum::actingAs($super);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.sidebar./reports', true)
            ->assertJsonPath('data.sidebar./leads', true)
            ->assertJsonPath('data.sidebar./manage-sidebar', true)
            ->assertJsonPath('data.sidebar./branches', true);
    }

    public function test_employee_cannot_edit_lead_details_until_sidebar_allows_it(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $manager = $this->createUser([
            'email' => 'mgr@acme.test', 'role' => 'manager', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);
        $employee = $this->createUser([
            'email' => 'emp@acme.test', 'role' => 'employee', 'branch_id' => $branch->id,
            'manager_id' => $manager->id, 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $course = $this->createCourse(['branch_id' => $branch->id, 'name' => 'BCA', 'code' => 'BCA']);
        $lead = $this->createLead([
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'assigned_to' => $employee->id,
            'course_id' => $course->id,
            'first_name' => 'Ada',
            'status' => 'new',
            'lead_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($employee);
        $this->putJson('/api/leads/'.$lead->id, ['first_name' => 'Changed'])
            ->assertForbidden();
        $this->putJson('/api/leads/'.$lead->id, ['status' => 'contacted'])
            ->assertOk();

        Sanctum::actingAs($super);
        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $branch->id,
            'manager' => ['/leads' => true],
            'employee' => ['/leads/edit' => true],
        ])->assertOk()->assertJsonPath('data.employee./leads/edit', true);

        Sanctum::actingAs($employee);
        $this->putJson('/api/leads/'.$lead->id, ['first_name' => 'Changed'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Changed');
    }
}
