<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\AttendanceRecord;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Notification;
use App\Models\Proposal;
use App\Services\PipelineSetupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeletedLeadVisibilityTest extends TestCase
{
    private function fixture(): array
    {
        $owner = $this->createCompany();
        $branch = $this->createBranch();
        $course = $this->createCourse(['branch_id' => $branch->id, 'price' => 100, 'duration_value' => 1, 'duration_unit' => 'years']);
        $batch = $this->createBatch(['branch_id' => $branch->id, 'course_id' => $course->id, 'status' => 'ongoing']);
        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($branch->id);
        $students = [];
        foreach (['ActiveStudent', 'DeletedStudent'] as $name) {
            $lead = $this->createLead(['first_name' => $name, 'branch_id' => $branch->id, 'course_id' => $course->id, 'created_by' => $owner->id, 'assigned_to' => $owner->id, 'status' => 'meeting']);
            $admission = Admission::create(['branch_id' => $branch->id, 'lead_id' => $lead->id, 'course_id' => $course->id, 'batch_id' => $batch->id, 'enrollment_id' => 'ENROLL-'.$name, 'admission_date' => now()->toDateString(), 'status' => 'admitted', 'hostel_stay' => true, 'hostel_amount' => 50]);
            $deals = [];
            foreach (['course' => 100, 'hostel' => 50] as $kind => $amount) {
                $deal = $this->createDeal(['branch_id' => $branch->id, 'lead_id' => $lead->id, 'fee_kind' => $kind, 'created_by' => $owner->id, 'assigned_to' => $owner->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->where('is_won', true)->first()->id, 'title' => $kind.' '.$name, 'value' => $amount, 'status' => 'won', 'closed_at' => now()->toDateString()]);
                DealPayment::create(['branch_id' => $branch->id, 'deal_id' => $deal->id, 'created_by' => $owner->id, 'amount' => $amount, 'payment_date' => now()->toDateString(), 'payment_mode' => 'upi', 'txn_or_utr_number' => 'REFERENCE-'.$name.'-'.$kind]);
                $deals[$kind] = $deal;
            }
            $activity = Activity::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'branch_id' => $branch->id, 'created_by' => $owner->id, 'assigned_to' => $owner->id, 'subject_type' => Lead::class, 'subject_id' => $lead->id, 'type' => 'meeting', 'title' => 'Meeting '.$name, 'due_at' => now()->addHour(), 'is_done' => false]);
            $dealActivity = Activity::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'branch_id' => $branch->id, 'created_by' => $owner->id, 'subject_type' => 'deal', 'subject_id' => $deals['course']->id, 'type' => 'task', 'title' => 'Fee task '.$name]);
            $note = Note::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'created_by' => $owner->id, 'notable_type' => Deal::class, 'notable_id' => $deals['course']->id, 'content' => 'Note '.$name]);
            $proposal = Proposal::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'branch_id' => $branch->id, 'lead_id' => $lead->id, 'created_by' => $owner->id, 'title' => 'Proposal '.$name, 'theme' => 'modern', 'status' => 'draft', 'conversation_notes' => 'Historical conversation', 'content' => []]);
            $document = AdmissionDocument::create(['admission_id' => $admission->id, 'file_name' => $name.'.pdf', 'path' => 'admissions/'.$name.'.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'doc_type' => 'other', 'uploaded_by' => $owner->id]);
            $attendance = AttendanceRecord::create(['branch_id' => $branch->id, 'course_id' => $course->id, 'batch_id' => $batch->id, 'admission_id' => $admission->id, 'attendance_date' => now()->toDateString(), 'status' => 'present', 'marked_by' => $owner->id]);
            $notifications = [];
            foreach (['lead_id' => $lead->id, 'deal_id' => $deals['course']->id, 'activity_id' => $activity->id] as $key => $id) {
                $notifications[] = Notification::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'user_id' => $owner->id, 'type' => 'assigned', 'title' => $name, 'message' => 'Reminder '.$name, 'data' => [$key => $id]]);
            }
            $students[] = compact('lead', 'admission', 'deals', 'activity', 'dealActivity', 'note', 'proposal', 'document', 'attendance', 'notifications');
        }
        Activity::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'branch_id' => $branch->id, 'created_by' => $owner->id, 'type' => 'task', 'title' => 'Standalone task']);
        Notification::forceCreate(['organization_id' => DB::table('organizations')->value('id'), 'user_id' => $owner->id, 'type' => 'general', 'title' => 'General', 'message' => 'General announcement']);
        Sanctum::actingAs($owner);
        [$active, $hidden] = $students;

        return compact('owner', 'branch', 'course', 'batch', 'active', 'hidden');
    }

    private function deleteLead(array $data): void
    {
        $this->deleteJson('/api/leads/'.$data['hidden']['lead']->id)->assertOk();
    }

    public function test_delete_hides_admissions_course_and_hostel_fees_for_every_role_without_removing_history(): void
    {
        $data = $this->fixture();
        $payments = DB::table('deal_payments')->orderBy('id')->get()->toJson();
        $this->getJson('/api/admissions')->assertOk()->assertJsonPath('meta.total', 2);
        $this->deleteLead($data);
        $deletedAdmission = DB::table('admissions')->where('id', $data['hidden']['admission']->id)->first();
        foreach (['superadmin', 'manager', 'employee'] as $role) {
            $user = $role === 'superadmin' ? $data['owner'] : $this->createUser(['name' => $role, 'email' => $role.'@visibility.test', 'role' => $role, 'branch_id' => $data['branch']->id]);
            foreach ([$data['active'], $data['hidden']] as $student) {
                $student['lead']->update(['assigned_to' => $user->id]);
                foreach ($student['deals'] as $deal) {
                    $deal->update(['assigned_to' => $user->id]);
                }
            }
            Sanctum::actingAs($user);
            $this->getJson('/api/admissions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $data['active']['admission']->id);
            foreach (['course', 'hostel'] as $kind) {
                $response = $this->getJson('/api/deals?fee_kind='.$kind)->assertOk();
                $this->assertNotContains($data['hidden']['deals'][$kind]->id, array_column($response->json('data'), 'id'));
                $this->assertContains($data['active']['deals'][$kind]->id, array_column($response->json('data'), 'id'));
            }
        }
        $this->assertEquals($deletedAdmission, DB::table('admissions')->where('id', $data['hidden']['admission']->id)->first());
        $this->assertSame($payments, DB::table('deal_payments')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('admissions', 2);
        $this->assertDatabaseCount('deals', 4);
        $this->assertDatabaseCount('deal_payments', 4);
    }

    public function test_direct_details_receipts_and_mutations_are_blocked_after_deletion(): void
    {
        $data = $this->fixture();
        $this->deleteLead($data);
        $hidden = $data['hidden'];
        foreach (['leads/'.$hidden['lead']->id, 'leads/'.$hidden['lead']->id.'/timeline', 'admissions/'.$hidden['admission']->id,
            'deals/'.$hidden['deals']['course']->id, 'deals/'.$hidden['deals']['hostel']->id.'/payments',
            'activities/'.$hidden['activity']->id, 'activities/'.$hidden['dealActivity']->id,
            'proposals/'.$hidden['proposal']->id, 'admissions/'.$hidden['admission']->id.'/documents/'.$hidden['document']->id.'/download'] as $url) {
            $this->getJson('/api/'.$url)->assertNotFound();
        }
        $this->putJson('/api/admissions/'.$hidden['admission']->id, [])->assertNotFound();
        $this->postJson('/api/deals/'.$hidden['deals']['course']->id.'/payments', ['amount' => 1])->assertNotFound();
        $this->putJson('/api/notes/'.$hidden['note']->id, ['content' => 'changed'])->assertNotFound();
        $this->patchJson('/api/attendance/'.$hidden['attendance']->id, ['status' => 'absent'])->assertNotFound();
        $this->patchJson('/api/notifications/'.$hidden['notifications'][0]->id.'/read')->assertNotFound();
        $this->getJson('/api/deals/'.$data['active']['deals']['course']->id.'/payments')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_dashboard_reports_aggregates_and_exports_exclude_deleted_leads(): void
    {
        $data = $this->fixture();
        $this->deleteLead($data);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.stats.total_leads', 1)->assertJsonPath('data.stats.won_deals', 1);
        $this->getJson('/api/reports')->assertOk()->assertJsonPath('data.admissions.total', 1);
        $this->getJson('/api/reports/admissions')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/reports/revenue')->assertOk()->assertJsonPath('meta.total', 2);
        foreach (['leads', 'admissions', 'revenue'] as $report) {
            foreach (['csv', 'xlsx'] as $format) {
                $body = $this->get('/api/reports/'.$report.'/export?format='.$format)->assertOk()->streamedContent();
                $this->assertStringContainsString('ActiveStudent', $body);
                $this->assertStringNotContainsString('DeletedStudent', $body);
            }
        }
    }

    public function test_activities_calendar_attendance_notes_and_notifications_follow_active_lead_visibility(): void
    {
        $data = $this->fixture();
        $this->deleteLead($data);
        $this->getJson('/api/activities')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/calendar?date_from='.now()->toDateString().'&date_to='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(1, 'data');
        $attendance = $this->getJson('/api/attendance/students?course_id='.$data['course']->id.'&batch_id='.$data['batch']->id.'&date='.now()->toDateString())->assertOk();
        $this->assertStringContainsString('ActiveStudent', $attendance->getContent());
        $this->assertStringNotContainsString('DeletedStudent', $attendance->getContent());
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 4);
        $this->getJson('/api/notes?notable_type=deal&notable_id='.$data['hidden']['deals']['course']->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filter_does_not_leak_into_console_conversion_or_historical_queries(): void
    {
        $data = $this->fixture();
        $this->deleteLead($data);
        $this->getJson('/api/admissions')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(2, Admission::count());
        $this->assertSame(4, DealPayment::count());
        $this->assertSame(0, Artisan::call('fees:convert-first-period'));
        $output = Artisan::output();
        $this->assertStringContainsString('"admissions_excluded_lead":1', $output);
        $this->assertStringContainsString('"count":4,"total":"300.00"', $output);
        $this->assertNull($data['active']['admission']->fresh()->fee_schedule);
        $this->assertNull($data['hidden']['admission']->fresh()->fee_schedule);
    }

    public function test_deleted_students_enrollment_numbers_remain_reserved_for_new_admissions(): void
    {
        $data = $this->fixture();
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $data['branch']->code)).'-'.now()->year.'-';
        $data['active']['admission']->update(['enrollment_id' => $prefix.'0001']);
        $data['hidden']['admission']->update(['enrollment_id' => $prefix.'0002']);
        $this->deleteLead($data);
        $lead = $this->createLead(['first_name' => 'NewStudent', 'branch_id' => $data['branch']->id, 'course_id' => $data['course']->id, 'created_by' => $data['owner']->id, 'assigned_to' => $data['owner']->id, 'status' => 'new']);
        $pipeline = $data['active']['deals']['course']->pipeline;
        $this->postJson('/api/leads/'.$lead->id.'/convert', ['title' => 'New fees', 'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->where('is_won', false)->first()->id])->assertCreated();
        $this->assertSame($prefix.'0003', Admission::where('lead_id', $lead->id)->firstOrFail()->enrollment_id);
        $this->assertSame($prefix.'0002', $data['hidden']['admission']->fresh()->enrollment_id);
    }
}
