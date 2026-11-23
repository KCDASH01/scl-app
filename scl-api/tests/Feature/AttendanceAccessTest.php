<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AttendanceRecord;
use App\Services\PipelineSetupService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceAccessTest extends TestCase
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

        $eastCourse = $this->createCourse(['branch_id' => $east->id, 'name' => 'BCA', 'code' => 'BCA', 'price' => 45000]);
        $westCourse = $this->createCourse(['branch_id' => $west->id, 'name' => 'MCA', 'code' => 'MCA']);
        $eastBatch = $this->createBatch([
            'branch_id' => $east->id, 'course_id' => $eastCourse->id, 'code' => 'E26', 'status' => 'upcoming',
        ]);
        $westBatch = $this->createBatch([
            'branch_id' => $west->id, 'course_id' => $westCourse->id, 'code' => 'W26', 'status' => 'upcoming',
        ]);

        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($east->id);
        $stage = $pipeline->stages()->first();

        return compact(
            'super', 'east', 'west', 'eastMgr', 'westMgr', 'eastEmp',
            'eastCourse', 'westCourse', 'eastBatch', 'westBatch', 'pipeline', 'stage'
        );
    }

    private function makeLead(array $data, array $attrs = []): \App\Models\Lead
    {
        return $this->createLead(array_merge([
            'branch_id'   => $data['east']->id,
            'course_id'   => $data['eastCourse']->id,
            'created_by'  => $data['eastMgr']->id,
            'assigned_to' => $data['eastEmp']->id,
            'first_name'  => 'Student',
            'last_name'   => 'One',
            'phone'       => '9876500001',
            'status'      => 'new',
            'lead_date'   => now()->toDateString(),
        ], $attrs));
    }

    private function admit(array $data, \App\Models\Lead $lead, $batch, $course): Admission
    {
        $this->postJson('/api/leads/'.$lead->id.'/convert', [
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
            'title'       => 'Fee for '.$lead->first_name,
            'value'       => 10000,
        ])->assertCreated();

        $admission = Admission::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id' => $course->id,
            'batch_id'  => $batch->id,
        ])->assertOk();

        return $admission->fresh();
    }

    public function test_mark_attendance_checked_present_unchecked_absent(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $presentLead = $this->makeLead($data, ['first_name' => 'Present', 'phone' => '9876500011']);
        $absentLead = $this->makeLead($data, ['first_name' => 'Absent', 'phone' => '9876500012']);
        $present = $this->admit($data, $presentLead, $data['eastBatch'], $data['eastCourse']);
        $absent = $this->admit($data, $absentLead, $data['eastBatch'], $data['eastCourse']);

        $payload = $this->getJson('/api/attendance/students?course_id='.$data['eastCourse']->id.'&batch_id='.$data['eastBatch']->id.'&date='.now()->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $payload['total']);
        $this->assertFalse($payload['is_marked']);

        $saved = $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => now()->toDateString(),
            'marked_at'             => now()->toDateTimeString(),
            'present_admission_ids' => [$present->id],
        ])->assertOk()->json('data');

        $this->assertTrue($saved['is_marked']);
        $this->assertSame(1, $saved['present_count']);
        $this->assertSame(1, $saved['absent_count']);

        $byId = collect($saved['students'])->keyBy('admission_id');
        $this->assertSame('present', $byId[$present->id]['status']);
        $this->assertSame('absent', $byId[$absent->id]['status']);
        $this->assertSame('Present One', $byId[$present->id]['student_name']);
    }

    public function test_status_is_editable_and_branch_scoped(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $lead = $this->makeLead($data, ['phone' => '9876500021']);
        $admission = $this->admit($data, $lead, $data['eastBatch'], $data['eastCourse']);

        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => now()->toDateString(),
            'present_admission_ids' => [$admission->id],
        ])->assertOk();

        $record = AttendanceRecord::query()->where('admission_id', $admission->id)->firstOrFail();
        $this->assertSame('present', $record->status);

        $this->patchJson('/api/attendance/'.$record->id, ['status' => 'absent'])
            ->assertOk()
            ->assertJsonPath('data.status', 'absent');

        Sanctum::actingAs($data['westMgr']);
        $this->getJson('/api/attendance/students?course_id='.$data['eastCourse']->id.'&batch_id='.$data['eastBatch']->id.'&date='.now()->toDateString())
            ->assertForbidden();
        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => now()->toDateString(),
            'present_admission_ids' => [$admission->id],
        ])->assertForbidden();
        $this->patchJson('/api/attendance/'.$record->id, ['status' => 'present'])->assertForbidden();
    }

    public function test_employee_can_take_attendance_in_own_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $lead = $this->makeLead($data, ['phone' => '9876500031']);
        $admission = $this->admit($data, $lead, $data['eastBatch'], $data['eastCourse']);

        Sanctum::actingAs($data['eastEmp']);
        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => now()->toDateString(),
            'present_admission_ids' => [$admission->id],
        ])->assertOk()->assertJsonPath('data.present_count', 1);

        $this->assertSame(1, AttendanceRecord::query()->where('marked_by', $data['eastEmp']->id)->count());
    }

    public function test_course_with_subjects_is_marked_once_per_subject_per_day(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $physics = \App\Models\Subject::create([
            'branch_id' => $data['east']->id,
            'course_id' => $data['eastCourse']->id,
            'name'      => 'Physics',
            'code'      => 'PHY',
        ]);
        $chemistry = \App\Models\Subject::create([
            'branch_id' => $data['east']->id,
            'course_id' => $data['eastCourse']->id,
            'name'      => 'Chemistry',
            'code'      => 'CHE',
        ]);

        $lead = $this->makeLead($data, ['phone' => '9876500041']);
        $admission = $this->admit($data, $lead, $data['eastBatch'], $data['eastCourse']);
        $today = now()->toDateString();

        $this->getJson('/api/attendance/subjects?course_id='.$data['eastCourse']->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => $today,
            'present_admission_ids' => [$admission->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['subject_id']);

        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => $today,
            'subject_id'            => $physics->id,
            'present_admission_ids' => [$admission->id],
        ])->assertOk()->assertJsonPath('data.subject.id', $physics->id);

        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => $today,
            'subject_id'            => $chemistry->id,
            'present_admission_ids' => [],
        ])->assertOk()
            ->assertJsonPath('data.subject.id', $chemistry->id)
            ->assertJsonPath('data.absent_count', 1);

        $this->assertSame(2, AttendanceRecord::query()->where('admission_id', $admission->id)->whereDate('attendance_date', $today)->count());
        $this->assertSame('present', AttendanceRecord::query()->where('subject_id', $physics->id)->value('status'));
        $this->assertSame('absent', AttendanceRecord::query()->where('subject_id', $chemistry->id)->value('status'));

        $physicsList = $this->getJson('/api/attendance/students?course_id='.$data['eastCourse']->id.'&batch_id='.$data['eastBatch']->id.'&date='.$today.'&subject_id='.$physics->id)
            ->assertOk()
            ->json('data.students');
        $this->assertSame('present', $physicsList[0]['status']);
    }

    public function test_future_attendance_date_is_rejected(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $lead = $this->makeLead($data, ['phone' => '9876500099']);
        $admission = $this->admit($data, $lead, $data['eastBatch'], $data['eastCourse']);
        $tomorrow = now()->addDay()->toDateString();

        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => $tomorrow,
            'present_admission_ids' => [$admission->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['date']);

        $this->getJson('/api/attendance/students?course_id='.$data['eastCourse']->id.'&batch_id='.$data['eastBatch']->id.'&date='.$tomorrow)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $this->postJson('/api/attendance/mark', [
            'course_id'             => $data['eastCourse']->id,
            'batch_id'              => $data['eastBatch']->id,
            'date'                  => now()->subDay()->toDateString(),
            'present_admission_ids' => [$admission->id],
        ])->assertOk();
    }
}
