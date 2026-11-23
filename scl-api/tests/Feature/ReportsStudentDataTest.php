<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\DealPayment;
use App\Services\PipelineSetupService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsStudentDataTest extends TestCase
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

        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($east->id);
        $stage = $pipeline->stages()->first();

        return compact(
            'super', 'east', 'west', 'eastMgr', 'westMgr', 'eastEmp',
            'eastCourse', 'westCourse', 'eastBatch', 'pipeline', 'stage'
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
            'phone'       => '9876543210',
            'status'      => 'new',
            'lead_date'   => now()->toDateString(),
        ], $attrs));
    }

    private function convert(array $data, \App\Models\Lead $lead, array $extra = []): array
    {
        return $this->postJson('/api/leads/'.$lead->id.'/convert', array_merge([
            'pipeline_id' => $data['pipeline']->id,
            'stage_id'    => $data['stage']->id,
            'title'       => 'Fee for '.$lead->first_name,
            'value'       => 10000,
        ], $extra))->assertCreated()->json();
    }

    public function test_analytics_includes_admission_student_metrics(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $lead = $this->makeLead($data);
        $this->convert($data, $lead);

        $payload = $this->getJson('/api/reports')->assertOk()->json('data');

        $this->assertSame(1, $payload['admissions']['total']);
        $this->assertSame(1, $payload['admissions']['pending']);
        $this->assertSame(0, $payload['admissions']['admitted']);
        $this->assertSame(1, $payload['admissions']['this_month']);
        $this->assertSame('BCA', $payload['admissions_by_course'][0]['course']);
        $this->assertSame(1, $payload['admissions_by_course'][0]['count']);
    }

    public function test_admission_report_filters_search_and_export(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $pending = $this->makeLead($data, ['first_name' => 'Anita', 'last_name' => 'Das', 'phone' => '9876543211']);
        $admittedLead = $this->makeLead($data, ['first_name' => 'Rahul', 'last_name' => 'Sen', 'phone' => '9876543212']);
        $this->convert($data, $pending);
        $this->convert($data, $admittedLead);

        $admitted = Admission::query()->where('lead_id', $admittedLead->id)->first();
        $this->putJson('/api/admissions/'.$admitted->id, [
            'course_id' => $data['eastCourse']->id,
            'batch_id'  => $data['eastBatch']->id,
        ])->assertOk();

        $report = $this->getJson('/api/reports/admissions')->assertOk()->json();
        $this->assertSame(2, $report['data']['summary']['total']);
        $this->assertSame(1, $report['data']['summary']['pending']);
        $this->assertSame(1, $report['data']['summary']['admitted']);
        $this->assertCount(2, $report['data']['rows']);

        $search = $this->getJson('/api/reports/admissions?search=Anita')->assertOk()->json('data.rows');
        $this->assertCount(1, $search);
        $this->assertSame('Anita Das', $search[0]['student_name']);

        $enrollment = Admission::query()->where('lead_id', $pending->id)->value('enrollment_id');
        $byEnroll = $this->getJson('/api/reports/admissions?search='.$enrollment)->assertOk()->json('data.rows');
        $this->assertCount(1, $byEnroll);
        $this->assertSame($enrollment, $byEnroll[0]['enrollment_id']);

        $filtered = $this->getJson('/api/reports/admissions?status=admitted&course_id='.$data['eastCourse']->id)
            ->assertOk()->json('data.rows');
        $this->assertCount(1, $filtered);
        $this->assertSame('Rahul Sen', $filtered[0]['student_name']);
        $this->assertSame('admitted', $filtered[0]['status']);

        $export = $this->get('/api/reports/admissions/export?format=csv&search=Anita');
        $export->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));
        $this->assertStringContainsString('Anita Das', $export->streamedContent());
        $this->assertStringContainsString('Enrollment ID', $export->streamedContent());
    }

    public function test_admission_report_is_branch_and_assignee_scoped(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $eastLead = $this->makeLead($data, ['first_name' => 'East', 'last_name' => 'Student', 'phone' => '9876543213']);
        $westLead = $this->makeLead($data, [
            'branch_id'   => $data['west']->id,
            'course_id'   => $data['westCourse']->id,
            'created_by'  => $data['westMgr']->id,
            'assigned_to' => $data['westMgr']->id,
            'first_name'  => 'West',
            'last_name'   => 'Student',
            'phone'       => '9876543214',
        ]);
        $this->convert($data, $eastLead);
        $this->convert($data, $westLead);

        Sanctum::actingAs($data['westMgr']);
        $westRows = $this->getJson('/api/reports/admissions')->assertOk()->json('data.rows');
        $this->assertCount(1, $westRows);
        $this->assertSame('West Student', $westRows[0]['student_name']);

        Sanctum::actingAs($data['eastEmp']);
        $empRows = $this->getJson('/api/reports/admissions')->assertOk()->json('data.rows');
        $this->assertCount(1, $empRows);
        $this->assertSame('East Student', $empRows[0]['student_name']);
    }

    public function test_lead_and_revenue_reports_include_student_fields(): void
    {
        $data = $this->seedHierarchy();
        Sanctum::actingAs($data['super']);

        $lead = $this->makeLead($data, ['first_name' => 'Priya', 'last_name' => 'Nair', 'phone' => '9876543215']);
        $this->convert($data, $lead, [
            'payment' => [
                'amount'       => 2000,
                'payment_date' => now()->toDateString(),
                'payment_mode' => 'upi',
            ],
        ]);

        $enrollment = Admission::query()->where('lead_id', $lead->id)->value('enrollment_id');

        $leads = $this->getJson('/api/reports/leads?search=Priya')->assertOk()->json('data.rows');
        $this->assertCount(1, $leads);
        $this->assertSame('Priya Nair', $leads[0]['student_name']);
        $this->assertSame($enrollment, $leads[0]['enrollment_id']);
        $this->assertSame('BCA', $leads[0]['course']);

        $byEnroll = $this->getJson('/api/reports/leads?search='.$enrollment)->assertOk()->json('data.rows');
        $this->assertCount(1, $byEnroll);

        $this->assertSame(1, DealPayment::query()->count());

        $revenue = $this->getJson('/api/reports/revenue?search=Priya')->assertOk()->json('data.rows');
        $this->assertCount(1, $revenue);
        $this->assertSame('Priya Nair', $revenue[0]['student_name']);
        $this->assertSame($enrollment, $revenue[0]['enrollment_id']);
        $this->assertSame('BCA', $revenue[0]['course']);
        $this->assertEquals(2000, (float) $revenue[0]['transaction_amount']);
    }
}
