<?php

namespace Tests\Feature;

use App\Models\Course;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseBatchAccessTest extends TestCase
{
    private function seedHierarchy(): array
    {
        $super = $this->createCompany();
        $east = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $west = $this->createBranch(['name' => 'West', 'code' => 'WEST']);
        $inactive = $this->createBranch(['name' => 'Closed', 'code' => 'CLOSED', 'is_active' => false]);

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

        return compact('super', 'east', 'west', 'inactive', 'eastMgr', 'westMgr', 'eastEmp');
    }

    public function test_superadmin_can_create_course_and_batch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $course = $this->postJson('/api/courses', [
            'branch_id'      => $data['east']->id,
            'name'           => 'BCA',
            'code'           => 'BCA',
            'duration_value' => 3,
            'duration_unit'  => 'years',
            'price'          => 45000,
        ])->assertCreated()->json('data');

        $this->assertSame(45000.0, (float) $course['price']);

        $this->assertSame($data['east']->id, $course['branch_id']);
        $this->assertSame('BCA', $course['code']);

        $batch = $this->postJson('/api/courses/'.$course['id'].'/batches', [
            'name'       => 'Morning 2026',
            'code'       => 'M26',
            'start_date' => '2026-06-01',
            'end_date'   => '2027-05-31',
            'capacity'   => 40,
            'schedule'   => 'Monday–Friday, 10 AM–12 PM',
            'status'     => 'upcoming',
        ])->assertCreated()->json('data');

        $this->assertSame($course['id'], $batch['course_id']);
        $this->assertSame($data['east']->id, $batch['branch_id']);
        $this->assertSame(40, $batch['capacity']);
    }

    public function test_course_requires_a_specific_branch(): void
    {
        $super = $this->createCompany();
        Sanctum::actingAs($super);

        $this->postJson('/api/courses', [
            'name' => 'BCA',
            'code' => 'BCA',
        ])->assertStatus(422);
    }

    public function test_course_code_unique_within_branch_but_reusable_across_branches(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $this->postJson('/api/courses', [
            'branch_id' => $data['east']->id, 'name' => 'BCA', 'code' => 'BCA', 'price' => 10000,
        ])->assertCreated();

        $this->postJson('/api/courses', [
            'branch_id' => $data['east']->id, 'name' => 'BCA Duplicate', 'code' => 'BCA', 'price' => 10000,
        ])->assertStatus(422);

        $this->postJson('/api/courses', [
            'branch_id' => $data['west']->id, 'name' => 'BCA', 'code' => 'BCA', 'price' => 10000,
        ])->assertCreated();
    }

    public function test_batch_code_unique_within_course(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastCourse = $this->createCourse(['branch_id' => $data['east']->id, 'name' => 'BCA', 'code' => 'BCA']);
        $westCourse = $this->createCourse(['branch_id' => $data['west']->id, 'name' => 'BCA', 'code' => 'BCA']);

        $this->postJson('/api/courses/'.$eastCourse->id.'/batches', [
            'name' => 'Morning', 'code' => 'M26', 'start_date' => '2026-06-01',
        ])->assertCreated();

        $this->postJson('/api/courses/'.$eastCourse->id.'/batches', [
            'name' => 'Morning 2', 'code' => 'M26', 'start_date' => '2026-07-01',
        ])->assertStatus(422);

        $this->postJson('/api/courses/'.$westCourse->id.'/batches', [
            'name' => 'Morning', 'code' => 'M26', 'start_date' => '2026-06-01',
        ])->assertCreated();
    }

    public function test_batch_dates_and_capacity_validation(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $course = $this->createCourse(['branch_id' => $data['east']->id]);

        $this->postJson('/api/courses/'.$course->id.'/batches', [
            'name' => 'A', 'code' => 'A1', 'start_date' => '2026-06-10', 'end_date' => '2026-06-01',
        ])->assertStatus(422);

        $this->postJson('/api/courses/'.$course->id.'/batches', [
            'name' => 'A', 'code' => 'A1', 'start_date' => '2026-06-01', 'capacity' => 0,
        ])->assertStatus(422);

        $this->postJson('/api/courses/'.$course->id.'/batches', [
            'name' => 'A', 'code' => 'A1', 'start_date' => '2026-06-01', 'capacity' => 25,
        ])->assertCreated();
    }

    public function test_manager_and_employee_cannot_mutate_courses_or_batches(): void
    {
        $data = $this->seedHierarchy();
        $course = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'BCA']);
        $batch = $this->createBatch([
            'branch_id' => $data['east']->id, 'course_id' => $course->id, 'code' => 'M26',
        ]);

        foreach ([$data['eastMgr'], $data['eastEmp']] as $user) {
            Sanctum::actingAs($user);

            $this->postJson('/api/courses', [
                'branch_id' => $data['east']->id, 'name' => 'MCA', 'code' => 'MCA',
            ])->assertForbidden();

            $this->putJson('/api/courses/'.$course->id, [
                'name' => 'Hacked', 'code' => 'BCA',
            ])->assertForbidden();

            $this->patchJson('/api/courses/'.$course->id.'/status')->assertForbidden();
            $this->deleteJson('/api/courses/'.$course->id)->assertForbidden();

            $this->postJson('/api/courses/'.$course->id.'/batches', [
                'name' => 'Rogue', 'code' => 'R1', 'start_date' => '2026-06-01',
            ])->assertForbidden();

            $this->putJson('/api/batches/'.$batch->id, [
                'name' => 'Hacked', 'code' => 'M26', 'start_date' => '2026-06-01',
            ])->assertForbidden();

            $this->deleteJson('/api/batches/'.$batch->id)->assertForbidden();
        }
    }

    public function test_manager_can_read_own_branch_courses_but_not_other_branch(): void
    {
        $data = $this->seedHierarchy();
        $eastCourse = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'EASTC']);
        $westCourse = $this->createCourse(['branch_id' => $data['west']->id, 'code' => 'WESTC']);

        Sanctum::actingAs($data['eastMgr']);

        $ids = collect($this->getJson('/api/courses')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($eastCourse->id));
        $this->assertFalse($ids->contains($westCourse->id));

        $this->getJson('/api/courses/'.$eastCourse->id)->assertOk();
        $this->getJson('/api/courses/'.$westCourse->id)->assertNotFound();

        $this->getJson('/api/courses/options')->assertOk()
            ->assertJsonFragment(['id' => $eastCourse->id])
            ->assertJsonMissing(['code' => 'WESTC']);
    }

    public function test_relationship_tampering_is_rejected(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastCourse = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'EASTC']);
        $westCourse = $this->createCourse(['branch_id' => $data['west']->id, 'code' => 'WESTC']);

        $this->postJson('/api/courses/'.$eastCourse->id.'/batches', [
            'name' => 'Morning',
            'code' => 'M26',
            'start_date' => '2026-06-01',
            'course_id' => $westCourse->id,
            'branch_id' => $data['west']->id,
        ])->assertStatus(422);

        $batch = $this->createBatch([
            'branch_id' => $data['east']->id, 'course_id' => $eastCourse->id, 'code' => 'KEEP',
        ]);

        $this->putJson('/api/batches/'.$batch->id, [
            'name' => $batch->name,
            'code' => 'KEEP',
            'start_date' => $batch->start_date->toDateString(),
            'course_id' => $westCourse->id,
        ])->assertStatus(422);

        $this->putJson('/api/courses/'.$eastCourse->id, [
            'name' => 'Moved',
            'code' => 'EASTC',
            'price' => 10000,
            'branch_id' => $data['west']->id,
        ])->assertStatus(422)
            ->assertJsonFragment(['branch_id' => ['This course cannot be moved to another branch because it already has batches or other linked records.']]);
    }

    public function test_inactive_branch_and_course_restrictions(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $this->postJson('/api/courses', [
            'branch_id' => $data['inactive']->id, 'name' => 'Closed Course', 'code' => 'CL1',
        ])->assertStatus(422);

        $course = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'INACT', 'is_active' => false]);

        $this->postJson('/api/courses/'.$course->id.'/batches', [
            'name' => 'Should fail', 'code' => 'X1', 'start_date' => '2026-06-01',
        ])->assertStatus(422);

        $this->getJson('/api/courses/options')->assertOk()
            ->assertJsonMissing(['code' => 'INACT']);

        $this->getJson('/api/courses/'.$course->id)->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_cannot_delete_course_with_batches(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $course = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'DEL']);
        $this->createBatch(['branch_id' => $data['east']->id, 'course_id' => $course->id, 'code' => 'B1']);

        $this->deleteJson('/api/courses/'.$course->id)->assertStatus(422);

        $empty = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'EMPTY']);
        $this->deleteJson('/api/courses/'.$empty->id)->assertOk();
        $this->assertTrue(Course::withTrashed()->find($empty->id)->trashed());
    }

    public function test_lead_course_must_belong_to_selected_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastCourse = $this->createCourse(['branch_id' => $data['east']->id, 'code' => 'EASTLEAD']);
        $westCourse = $this->createCourse(['branch_id' => $data['west']->id, 'code' => 'WESTLEAD']);

        $this->postJson('/api/leads', [
            'first_name' => 'Student',
            'branch_id'  => $data['east']->id,
            'course_id'  => $westCourse->id,
        ])->assertStatus(422);

        $this->postJson('/api/leads', [
            'first_name' => 'Student',
            'branch_id'  => $data['east']->id,
            'course_id'  => $eastCourse->id,
        ])->assertCreated()
            ->assertJsonPath('data.course_id', $eastCourse->id)
            ->assertJsonPath('data.branch_id', $data['east']->id);
    }

    public function test_employee_cannot_query_another_branch_by_parameter(): void
    {
        $data = $this->seedHierarchy();
        $westCourse = $this->createCourse(['branch_id' => $data['west']->id, 'code' => 'WESTC']);
        Sanctum::actingAs($data['eastEmp']);

        $ids = collect($this->getJson('/api/courses?branch_id='.$data['west']->id)->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($westCourse->id));
    }
}
