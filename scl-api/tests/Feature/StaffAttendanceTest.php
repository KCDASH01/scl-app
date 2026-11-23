<?php

namespace Tests\Feature;

use App\Models\StaffAttendanceRecord;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAttendanceTest extends TestCase
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
        $otherEast = $this->createUser([
            'name' => 'Other East', 'email' => 'other.east@acme.test', 'role' => 'employee',
            'branch_id' => $east->id, 'manager_id' => $westMgr->id, 'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $westEmp = $this->createUser([
            'name' => 'West Emp', 'email' => 'west.emp@acme.test', 'role' => 'employee',
            'branch_id' => $west->id, 'manager_id' => $westMgr->id, 'password' => 'password',
            'email_verified_at' => now(),
        ]);

        return compact('super', 'east', 'west', 'eastMgr', 'westMgr', 'eastEmp', 'otherEast', 'westEmp');
    }

    public function test_superadmin_marks_every_employee_once_per_date_and_can_update(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $today = now()->toDateString();

        $listed = $this->getJson('/api/staff-attendance?date='.$today)
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.is_marked', false)
            ->json('data.employees');

        $this->assertSame(['employee'], collect($listed)->pluck('role')->unique()->values()->all());
        $this->assertNotContains($data['eastMgr']->id, collect($listed)->pluck('user_id')->all());

        $this->postJson('/api/staff-attendance/mark', [
            'date' => $today,
            'present_user_ids' => [$data['eastMgr']->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['present_user_ids']);

        $this->postJson('/api/staff-attendance/mark', [
            'date' => $today,
            'present_user_ids' => [$data['eastEmp']->id],
        ])->assertOk()
            ->assertJsonPath('data.present_count', 1)
            ->assertJsonPath('data.absent_count', 2);

        $this->assertSame(1, StaffAttendanceRecord::query()
            ->where('user_id', $data['eastEmp']->id)
            ->whereDate('attendance_date', $today)
            ->count());

        $this->postJson('/api/staff-attendance/mark', [
            'date' => $today,
            'present_user_ids' => [$data['eastEmp']->id],
        ])->assertOk()
            ->assertJsonPath('data.present_count', 1)
            ->assertJsonPath('data.absent_count', 2);

        $this->assertSame(1, StaffAttendanceRecord::query()
            ->where('user_id', $data['eastEmp']->id)
            ->whereDate('attendance_date', $today)
            ->count());

        $record = StaffAttendanceRecord::query()->where('user_id', $data['eastEmp']->id)->firstOrFail();
        $this->patchJson('/api/staff-attendance/'.$record->id, ['status' => 'absent'])
            ->assertOk()
            ->assertJsonPath('data.status', 'absent')
            ->assertJsonPath('data.user_id', $data['eastEmp']->id);

        $this->assertSame(3, StaffAttendanceRecord::query()->whereDate('attendance_date', $today)->count());
        $this->assertSame(0, StaffAttendanceRecord::query()->where('user_id', $data['eastMgr']->id)->count());
    }

    public function test_past_dates_are_allowed_and_future_dates_are_rejected(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $markedAt = now()->subDay()->setTime(9, 30, 0);

        $saved = $this->postJson('/api/staff-attendance/mark', [
            'date' => $yesterday,
            'marked_at' => $markedAt->format('Y-m-d H:i:s'),
            'present_user_ids' => [$data['westEmp']->id],
        ])->assertOk()
            ->assertJsonPath('data.date', $yesterday)
            ->assertJsonPath('data.present_count', 1)
            ->json('data.employees');

        $west = collect($saved)->firstWhere('user_id', $data['westEmp']->id);
        $this->assertSame('09:30', \Illuminate\Support\Carbon::parse($west['marked_at'])->format('H:i'));

        $this->postJson('/api/staff-attendance/mark', [
            'date' => now()->toDateString(),
            'marked_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'present_user_ids' => [$data['westEmp']->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['marked_at']);

        $this->getJson('/api/staff-attendance?date='.$tomorrow)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $this->postJson('/api/staff-attendance/mark', [
            'date' => $tomorrow,
            'present_user_ids' => [$data['westEmp']->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $this->assertSame(0, StaffAttendanceRecord::query()->whereDate('attendance_date', $tomorrow)->count());
    }

    public function test_manager_marks_only_their_own_branch_employees(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastMgr']);

        $today = now()->toDateString();

        $listed = $this->getJson('/api/staff-attendance?date='.$today)->assertOk()->json('data.employees');
        $ids = collect($listed)->pluck('user_id')->all();

        $this->assertSame([$data['eastEmp']->id], $ids);

        $this->postJson('/api/staff-attendance/mark', [
            'date' => $today,
            'present_user_ids' => [$data['westEmp']->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['present_user_ids']);

        $this->postJson('/api/staff-attendance/mark', [
            'date' => $today,
            'present_user_ids' => [$data['eastEmp']->id],
        ])->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.present_count', 1);

        $this->assertSame(0, StaffAttendanceRecord::query()->where('user_id', $data['westEmp']->id)->count());
    }

    public function test_employee_cannot_mark_staff_attendance(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['eastEmp']);

        $this->getJson('/api/staff-attendance?date='.now()->toDateString())->assertForbidden();
        $this->postJson('/api/staff-attendance/mark', [
            'date' => now()->toDateString(),
            'present_user_ids' => [$data['eastEmp']->id],
        ])->assertForbidden();
    }
}
