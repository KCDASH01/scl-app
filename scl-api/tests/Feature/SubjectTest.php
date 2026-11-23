<?php

namespace Tests\Feature;

use App\Models\Subject;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    public function test_subject_requires_a_course_and_stays_on_that_course(): void
    {
        $super = $this->createCompany();
        $east = $this->createBranch(['name' => 'East', 'code' => 'EAST']);
        $west = $this->createBranch(['name' => 'West', 'code' => 'WEST']);
        $nursing = $this->createCourse(['branch_id' => $east->id, 'name' => 'Nursing', 'code' => 'NUR']);
        $plus = $this->createCourse(['branch_id' => $west->id, 'name' => 'PLUS 2', 'code' => 'PLS']);
        Sanctum::actingAs($super);

        $this->getJson('/api/subjects')->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->postJson('/api/subjects', [
            'name' => 'Physics',
            'code' => 'PHY',
        ])->assertStatus(422)->assertJsonValidationErrors(['course_id']);

        $created = $this->postJson('/api/subjects', [
            'course_id' => $nursing->id,
            'name'      => 'Physics',
            'code'      => 'PHY',
        ])->assertCreated()->json('data');

        $this->assertSame($nursing->id, $created['course_id']);
        $this->assertSame($east->id, $created['branch_id']);

        $this->postJson('/api/subjects', [
            'course_id' => $nursing->id,
            'name'      => 'Physics',
            'code'      => 'PHY2',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $listed = $this->getJson('/api/subjects?course_id='.$nursing->id)->assertOk()->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame('PHY', $listed[0]['code']);

        $this->putJson('/api/subjects/'.$created['id'], [
            'course_id' => $plus->id,
            'name'      => 'Chemistry',
            'code'      => 'CHE',
        ])->assertOk()
            ->assertJsonPath('data.course_id', $plus->id)
            ->assertJsonPath('data.branch_id', $west->id);

        $this->assertSame(0, Subject::query()->where('course_id', $nursing->id)->count());
        $this->deleteJson('/api/subjects/'.$created['id'])->assertOk();
        $this->assertSoftDeleted('subjects', ['id' => $created['id']]);
    }

    public function test_manager_cannot_manage_subjects(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $course = $this->createCourse(['branch_id' => $branch->id, 'name' => 'Nursing', 'code' => 'NUR']);
        $manager = $this->createUser([
            'email' => 'mgr.subject@acme.test', 'role' => 'manager', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/subjects?course_id='.$course->id)->assertForbidden();
        $this->postJson('/api/subjects', [
            'course_id' => $course->id,
            'name'      => 'Physics',
            'code'      => 'PHY',
        ])->assertForbidden();
    }
}
