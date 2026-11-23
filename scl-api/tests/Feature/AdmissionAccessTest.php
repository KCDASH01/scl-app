<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Services\PipelineSetupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionAccessTest extends TestCase
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
        $westCourse = $this->createCourse(['branch_id' => $west->id, 'name' => 'BCA', 'code' => 'BCA']);
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
            'status'      => 'new',
            'lead_date'   => now()->toDateString(),
        ], $attrs));
    }

    private function convert(array $data, \App\Models\Lead $lead): array
    {
        return $this->postJson('/api/leads/'.$lead->id.'/convert', [
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
            'title'       => 'Fee for '.$lead->first_name,
            'value'       => 10000,
        ])->assertCreated()->json();
    }

    public function test_convert_creates_admission_with_enrollment_id(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $lead = $this->makeLead($data);
        $this->convert($data, $lead);

        $admission = Admission::query()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($admission);
        $this->assertSame('EAST-'.now()->year.'-0001', $admission->enrollment_id);
        $this->assertSame($data['east']->id, $admission->branch_id);
        $this->assertSame($data['eastCourse']->id, $admission->course_id);
        $this->assertNull($admission->batch_id);
        $this->assertSame('pending', $admission->status);
    }

    public function test_convert_autofills_deal_value_from_course_price(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);

        $deal = $this->postJson('/api/leads/'.$lead->id.'/convert', [
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
            'title'       => 'Fee for '.$lead->first_name,
        ])->assertCreated()->json('data');

        $this->assertEquals(45000, (float) $deal['value']);
    }

    public function test_enrollment_sequence_is_per_branch_and_never_reused(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastOne = $this->makeLead($data, ['first_name' => 'A']);
        $eastTwo = $this->makeLead($data, ['first_name' => 'B']);
        $westLead = $this->makeLead($data, [
            'branch_id'   => $data['west']->id,
            'course_id'   => $data['westCourse']->id,
            'created_by'  => $data['westMgr']->id,
            'assigned_to' => $data['westMgr']->id,
            'first_name'  => 'C',
        ]);

        $this->convert($data, $eastOne);
        $this->convert($data, $eastTwo);
        $this->convert($data, $westLead);

        $year = now()->year;
        $this->assertSame('EAST-'.$year.'-0001', Admission::query()->where('lead_id', $eastOne->id)->value('enrollment_id'));
        $this->assertSame('EAST-'.$year.'-0002', Admission::query()->where('lead_id', $eastTwo->id)->value('enrollment_id'));
        $this->assertSame('WEST-'.$year.'-0001', Admission::query()->where('lead_id', $westLead->id)->value('enrollment_id'));
    }

    public function test_add_deal_also_creates_admission(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);

        $this->postJson('/api/deals', [
            'branch_id'    => $data['east']->id,
            'lead_id'      => $lead->id,
            'pipeline_id'  => $data['pipeline']->id,
            'stage_id'     => $data['stage']->id,
            'title'        => 'Add deal convert',
            'value'        => 5000,
        ])->assertCreated();

        $this->assertSame('converted', $lead->fresh()->status);
        $this->assertNotNull(Admission::query()->where('lead_id', $lead->id)->first());
    }

    public function test_second_convert_does_not_create_another_admission(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);

        $this->postJson('/api/leads/'.$lead->id.'/convert', [
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
        ])->assertStatus(422);

        $this->assertSame(1, Admission::query()->where('lead_id', $lead->id)->count());
    }

    public function test_manager_cannot_see_other_branch_admission(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        Sanctum::actingAs($data['westMgr']);
        $ids = collect($this->getJson('/api/admissions')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($admission->id));
        $this->getJson('/api/admissions/'.$admission->id)->assertNotFound();
    }

    public function test_employee_only_sees_assigned_lead_admission(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $assigned = $this->makeLead($data, ['first_name' => 'Mine']);
        $other = $this->makeLead($data, [
            'first_name'  => 'Other',
            'assigned_to' => $data['eastMgr']->id,
        ]);
        $this->convert($data, $assigned);
        $this->convert($data, $other);

        Sanctum::actingAs($data['eastEmp']);
        $names = collect($this->getJson('/api/admissions')->assertOk()->json('data'))
            ->pluck('lead.first_name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Other'));
    }

    public function test_course_and_batch_must_match_admission_branch(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id' => $data['westCourse']->id,
        ])->assertStatus(422);

        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id' => $data['eastCourse']->id,
            'batch_id'  => $data['westBatch']->id,
        ])->assertStatus(422);

        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id'      => $data['eastCourse']->id,
            'batch_id'       => $data['eastBatch']->id,
            'admission_date' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.batch_id', $data['eastBatch']->id)
            ->assertJsonPath('data.status', 'admitted')
            ->assertJsonPath('data.enrollment_id', 'EAST-'.now()->year.'-0001');
    }

    public function test_changing_course_clears_batch_and_enrollment_id_is_locked(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $otherEast = $this->createCourse(['branch_id' => $data['east']->id, 'name' => 'MCA', 'code' => 'MCA']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id' => $data['eastCourse']->id,
            'batch_id'  => $data['eastBatch']->id,
        ])->assertOk()->assertJsonPath('data.status', 'admitted');

        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id'      => $otherEast->id,
            'enrollment_id'  => 'HACK-2026-9999',
        ])->assertOk()
            ->assertJsonPath('data.course_id', $otherEast->id)
            ->assertJsonPath('data.batch_id', null)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.enrollment_id', 'EAST-'.now()->year.'-0001');
    }

    public function test_document_upload_and_delete(): void
    {
        Storage::fake('local');
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $file = UploadedFile::fake()->create('id-proof.pdf', 120, 'application/pdf');
        $doc = $this->post('/api/admissions/'.$admission->id.'/documents', [
            'file'     => $file,
            'doc_type' => 'id_proof',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('id-proof.pdf', $doc['file_name']);
        $this->assertSame('id_proof', $doc['doc_type']);

        $this->get('/api/admissions/'.$admission->id.'/documents/'.$doc['id'].'/download')
            ->assertOk();

        $this->deleteJson('/api/admissions/'.$admission->id.'/documents/'.$doc['id'])
            ->assertOk();
        $this->getJson('/api/admissions/'.$admission->id)
            ->assertOk()
            ->assertJsonPath('data.documents', []);
    }

    public function test_cannot_delete_course_or_batch_with_admission(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $this->putJson('/api/admissions/'.$admission->id, [
            'course_id' => $data['eastCourse']->id,
            'batch_id'  => $data['eastBatch']->id,
        ])->assertOk();

        $this->deleteJson('/api/batches/'.$data['eastBatch']->id)->assertStatus(422);
        $this->deleteJson('/api/courses/'.$data['eastCourse']->id)->assertStatus(422);
    }

    public function test_hostel_stay_saves_amount_and_clears_it_when_unchecked(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $this->assertFalse((bool) $admission->hostel_stay);

        $this->putJson('/api/admissions/'.$admission->id, [
            'hostel_stay'   => true,
            'hostel_amount' => 18000,
        ])->assertOk()
            ->assertJsonPath('data.hostel_stay', true)
            ->assertJsonPath('data.hostel_amount', 18000);

        $listed = $this->getJson('/api/deals?fee_kind=hostel')->assertOk()->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame('Hostel Fee — Student One', $listed[0]['title']);
        $this->assertSame('Student One', $listed[0]['lead']['full_name']);

        $this->putJson('/api/admissions/'.$admission->id, [
            'hostel_stay' => true,
        ])->assertStatus(422);

        $this->putJson('/api/admissions/'.$admission->id, [
            'hostel_stay'   => false,
            'hostel_amount' => 18000,
        ])->assertOk()
            ->assertJsonPath('data.hostel_stay', false)
            ->assertJsonPath('data.hostel_amount', null);

        $this->assertSame([], $this->getJson('/api/deals?fee_kind=hostel')->assertOk()->json('data'));
    }

    public function test_hostel_fee_is_separate_from_course_fee(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $course = $this->convert($data, $lead);
        $this->assertSame('course', $course['data']['fee_kind'] ?? 'course');

        $admission = Admission::query()->where('lead_id', $lead->id)->first();
        $this->putJson('/api/admissions/'.$admission->id, [
            'hostel_stay'   => true,
            'hostel_amount' => 12000,
        ])->assertOk();

        $hostelFee = $this->getJson('/api/deals?fee_kind=hostel')->assertOk()->json('data.0');
        $this->assertSame('hostel', $hostelFee['fee_kind']);
        $this->assertSame('Student One', $hostelFee['lead']['full_name']);
        $this->assertEquals(12000, (float) $hostelFee['value']);

        $courseIds = collect($this->getJson('/api/deals')->assertOk()->json('data'))->pluck('fee_kind');
        $this->assertTrue($courseIds->every(fn ($kind) => ($kind ?: 'course') === 'course'));

        $hostel = $this->getJson('/api/deals?fee_kind=hostel')->assertOk()->json('data');
        $this->assertCount(1, $hostel);
        $this->assertSame('hostel', $hostel[0]['fee_kind']);

        $staying = collect($this->getJson('/api/admissions?hostel_stay=1')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($staying->contains($admission->id));

        $this->postJson('/api/deals', [
            'branch_id'   => $data['east']->id,
            'lead_id'     => $lead->id,
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
            'title'       => 'Second hostel fee',
            'fee_kind'    => 'hostel',
            'value'       => 1000,
        ])->assertStatus(422);
    }

    public function test_hostel_fee_requires_hostel_stay(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);

        $this->postJson('/api/deals', [
            'branch_id'   => $data['east']->id,
            'lead_id'     => $lead->id,
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
            'title'       => 'Hostel Fee — Student',
            'fee_kind'    => 'hostel',
            'value'       => 5000,
        ])->assertStatus(422);
    }

    public function test_admission_saves_batch_year_and_student_year(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $year = $this->postJson('/api/academic-years', ['name' => '2026-27'])->assertCreated()->json('data');

        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $this->putJson('/api/admissions/'.$admission->id, [
            'academic_year_id' => $year['id'],
            'student_year'     => '2nd',
        ])->assertOk()
            ->assertJsonPath('data.academic_year_id', $year['id'])
            ->assertJsonPath('data.student_year', '2nd')
            ->assertJsonPath('data.academic_year.name', '2026-27');

        $this->putJson('/api/admissions/'.$admission->id, [
            'student_year' => '5th',
        ])->assertStatus(422);
    }

    public function test_other_document_requires_a_name_and_keeps_each_name(): void
    {
        Storage::fake('local');
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);
        $lead = $this->makeLead($data);
        $this->convert($data, $lead);
        $admission = Admission::query()->where('lead_id', $lead->id)->first();

        $file = UploadedFile::fake()->create('scan.pdf', 40, 'application/pdf');
        $this->post('/api/admissions/'.$admission->id.'/documents', [
            'file'     => $file,
            'doc_type' => 'other',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $first = UploadedFile::fake()->create('caste.pdf', 40, 'application/pdf');
        $this->post('/api/admissions/'.$admission->id.'/documents', [
            'file'      => $first,
            'doc_type'  => 'other',
            'doc_label' => 'Caste certificate',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.doc_label', 'Caste certificate');

        $second = UploadedFile::fake()->create('income.pdf', 40, 'application/pdf');
        $this->post('/api/admissions/'.$admission->id.'/documents', [
            'file'      => $second,
            'doc_type'  => 'other',
            'doc_label' => 'Income certificate',
        ], ['Accept' => 'application/json'])->assertCreated();

        $labels = collect($this->getJson('/api/admissions/'.$admission->id)->assertOk()->json('data.documents'))
            ->pluck('doc_label')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['Caste certificate', 'Income certificate'], $labels);
    }
}
