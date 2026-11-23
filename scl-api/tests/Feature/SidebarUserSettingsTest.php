<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Services\SidebarSettingsService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SidebarUserSettingsTest extends TestCase
{
    public static function roles(): array
    {
        return [['manager', 'manager'], ['admin', 'manager'], ['employee', 'employee']];
    }

    #[DataProvider('roles')]
    public function test_user_inheritance_customization_and_reset(string $userRole, string $scopeRole): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $user = $this->createUser(['email' => 'custom@acme.test', 'role' => $userRole, 'branch_id' => $branch->id]);
        $peer = $this->createUser(['email' => 'peer@acme.test', 'role' => $userRole, 'branch_id' => $branch->id]);
        $base = ['branch_id' => $branch->id, 'role' => $scopeRole];
        Sanctum::actingAs($super);
        $this->putJson('/api/sidebar-settings', $base + ['map' => ['/reports' => false]])->assertOk();
        $this->getJson('/api/sidebar-settings?'.http_build_query($base + ['user_id' => $user->id]))
            ->assertOk()->assertJsonPath('data.has_customization', false)->assertJsonPath('data.map./reports', false);
        $this->putJson('/api/sidebar-settings', $base + ['user_id' => $user->id, 'map' => [
            '/reports' => true, '/dashboard' => false, '/branches' => true,
        ]])->assertOk()->assertJsonPath('data.has_customization', true)->assertJsonPath('data.map./reports', true)
            ->assertJsonMissingPath('data.map./dashboard')->assertJsonMissingPath('data.map./branches');
        $this->putJson('/api/sidebar-settings', $base + ['map' => ['/reports' => false, '/calendar' => false]])->assertOk();
        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.sidebar./reports', true)
            ->assertJsonPath('data.sidebar./calendar', false)->assertJsonPath('data.sidebar./dashboard', true);
        Sanctum::actingAs($peer);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.sidebar./reports', false);
        Sanctum::actingAs($super);
        $this->putJson('/api/sidebar-settings', $base + ['user_id' => $user->id, 'reset' => true])
            ->assertOk()->assertJsonPath('data.has_customization', false)->assertJsonPath('data.map./reports', false);
    }

    public function test_bulk_apply_and_legacy_save_preserve_other_scopes(): void
    {
        $super = $this->createCompany();
        $east = $this->createBranch();
        $west = $this->createBranch();
        $manager = $this->createUser(['email' => 'manager@acme.test', 'role' => 'manager', 'branch_id' => $east->id]);
        $employee = $this->createUser(['email' => 'employee@acme.test', 'role' => 'employee', 'branch_id' => $east->id]);
        $other = $this->createUser(['email' => 'west@acme.test', 'role' => 'manager', 'branch_id' => $west->id]);
        $service = app(SidebarSettingsService::class);
        foreach ([$manager, $employee, $other] as $user) {
            $service->saveScope($user->branch_id, $service->roleFor($user), $user, ['/reports' => false]);
        }
        $service->update($east->id, [], []);
        $this->assertNotNull($service->overrideFor($manager));
        Sanctum::actingAs($super);
        $this->putJson('/api/sidebar-settings', [
            'branch_id' => $east->id, 'role' => 'manager', 'map' => ['/reports' => true], 'clear_customizations' => true,
        ])->assertOk()->assertJsonPath('data.users.0.has_customization', false);
        $this->assertNull($service->overrideFor($manager));
        $this->assertNotNull($service->overrideFor($employee));
        $this->assertNotNull($service->overrideFor($other));
        $this->assertTrue($service->resolvedFor($manager)['/reports']);
        $this->assertFalse($service->resolvedFor($employee)['/reports']);
        $this->assertFalse($service->resolvedFor($other)['/reports']);
    }

    public function test_selection_is_scoped_and_requires_superadmin(): void
    {
        $super = $this->createCompany();
        $east = $this->createBranch();
        $west = $this->createBranch();
        $manager = $this->createUser(['email' => 'mgr@acme.test', 'role' => 'manager', 'branch_id' => $east->id]);
        $employee = $this->createUser(['email' => 'emp@acme.test', 'role' => 'employee', 'branch_id' => $east->id]);
        $other = $this->createUser(['email' => 'west@acme.test', 'role' => 'manager', 'branch_id' => $west->id]);
        $base = ['branch_id' => $east->id, 'role' => 'manager'];
        Sanctum::actingAs($super);
        $this->getJson('/api/sidebar-settings?'.http_build_query($base))->assertOk()
            ->assertJsonCount(1, 'data.users')->assertJsonPath('data.users.0.id', $manager->id);
        foreach ([$employee->id, $other->id, $super->id] as $id) {
            $this->putJson('/api/sidebar-settings', $base + ['user_id' => $id, 'map' => ['/reports' => false]])
                ->assertUnprocessable()->assertJsonValidationErrors('user_id');
            $this->getJson('/api/sidebar-settings?'.http_build_query($base + ['user_id' => $id]))->assertUnprocessable();
        }
        $this->putJson('/api/sidebar-settings', $base + ['reset' => true])->assertUnprocessable();
        $this->putJson('/api/sidebar-settings', $base + ['map' => ['/reports' => 'invalid']])->assertUnprocessable();
        foreach ([$manager, $employee] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/sidebar-settings?'.http_build_query($base))->assertForbidden();
            $this->getJson('/api/sidebar-settings?branch_id='.$west->id)->assertForbidden();
            $this->putJson('/api/sidebar-settings', $base + ['user_id' => $user->id, 'map' => []])->assertForbidden();
        }
    }

    public function test_personal_lead_edit_permissions_and_no_panel_restriction(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $employee = $this->createUser(['email' => 'emp@acme.test', 'role' => 'employee', 'branch_id' => $branch->id]);
        $lead = $this->createLead([
            'branch_id' => $branch->id, 'created_by' => $super->id, 'assigned_to' => $employee->id,
            'first_name' => 'Student', 'status' => 'new',
        ]);
        $service = app(SidebarSettingsService::class);
        $service->saveScope($branch->id, 'employee', $employee, ['/leads/edit' => true]);
        Sanctum::actingAs($employee);
        $this->putJson('/api/leads/'.$lead->id, ['first_name' => 'Allowed'])->assertOk();
        $service->saveScope($branch->id, 'employee', $employee, [], true);
        $this->putJson('/api/leads/'.$lead->id, ['first_name' => 'Blocked'])->assertForbidden();
        $staffRole = StaffRole::create(['name' => 'No panel', 'access' => 'none']);
        $employee->update(['staff_role_id' => $staffRole->id]);
        $employee->unsetRelation('staffRole');
        $service->saveScope($branch->id, 'employee', $employee, ['/reports' => true, '/leads/edit' => true]);
        $this->assertFalse($service->resolvedFor($employee)['/reports']);
        $this->assertFalse($service->employeeCanEditLeads($employee));
    }

    public function test_overrides_do_not_follow_user_to_another_branch_or_role(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $other = $this->createBranch();
        $user = $this->createUser(['role' => 'employee', 'branch_id' => $branch->id]);
        $service = app(SidebarSettingsService::class);
        $service->saveScope($branch->id, 'employee', $user, ['/reports' => false]);
        $user->update(['branch_id' => $other->id]);
        $this->assertTrue($service->resolvedFor($user)['/reports']);
        $user->update(['branch_id' => $branch->id, 'role' => 'manager']);
        $this->assertTrue($service->resolvedFor($user)['/reports']);
    }
}
