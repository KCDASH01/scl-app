<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\DealPayment;
use App\Services\PipelineSetupService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirstPeriodConversionTest extends TestCase
{
    private function legacy(bool $hostel = true, float $paid = 80): array
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $course = $this->createCourse(['branch_id' => $branch->id, 'price' => 100, 'duration_value' => null, 'duration_unit' => null]);
        $lead = $this->createLead(['first_name' => 'Legacy student', 'branch_id' => $branch->id, 'course_id' => $course->id, 'created_by' => $super->id, 'assigned_to' => $super->id, 'status' => 'converted']);
        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($branch->id);
        $stage = $pipeline->stages()->where('is_won', true)->firstOrFail();
        $admission = Admission::create(['branch_id' => $branch->id, 'lead_id' => $lead->id, 'course_id' => $course->id, 'enrollment_id' => 'LEGACY-TEST', 'status' => 'pending', 'hostel_stay' => $hostel, 'hostel_amount' => $hostel ? 50 : null]);
        $base = ['branch_id' => $branch->id, 'lead_id' => $lead->id, 'created_by' => $super->id, 'assigned_to' => $super->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'status' => $paid >= 80 ? 'won' : 'open', 'closed_at' => '2026-10-01'];
        $deal = $this->createDeal($base + ['fee_kind' => 'course', 'title' => 'Legacy course', 'value' => 80]);
        $payment = DealPayment::create(['branch_id' => $branch->id, 'deal_id' => $deal->id, 'created_by' => $super->id, 'amount' => $paid, 'payment_date' => '2026-10-01', 'payment_mode' => 'cash']);
        $hostelDeal = null;
        if ($hostel) {
            $hostelDeal = $this->createDeal(array_merge($base, ['fee_kind' => 'hostel', 'title' => 'Legacy hostel', 'value' => 50, 'status' => 'won']));
            DealPayment::create(['branch_id' => $branch->id, 'deal_id' => $hostelDeal->id, 'created_by' => $super->id, 'amount' => 50, 'payment_date' => '2026-10-01', 'payment_mode' => 'cash']);
        }
        Sanctum::actingAs($super);

        return compact('super', 'course', 'admission', 'deal', 'hostelDeal', 'payment');
    }

    private function extend($course, array $prices, int $duration = 3)
    {
        return $this->putJson('/api/courses/'.$course->id, [
            'name' => $course->name, 'code' => $course->code, 'duration_value' => $duration,
            'duration_unit' => 'years', 'period_prices' => $prices,
        ]);
    }

    public function test_preview_then_conversion_preserves_student_agreements_payments_and_is_repeat_safe(): void
    {
        extract($this->legacy());
        $before = [$course->fresh()->toArray(), $admission->fresh()->toArray(), $deal->fresh()->toArray(), $payment->fresh()->toArray()];
        $this->artisan('fees:convert-first-period')->assertExitCode(0);
        $this->assertEquals($before, [$course->fresh()->toArray(), $admission->fresh()->toArray(), $deal->fresh()->toArray(), $payment->fresh()->toArray()]);
        $this->assertDatabaseCount('first_period_conversion_backups', 0);
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $this->assertSame('years', $course->fresh()->duration_unit);
        $this->assertEquals(1, $course->fresh()->duration_value);
        $this->assertEquals(100, $course->fresh()->period_prices[0]['amount']);
        $this->assertEquals(80, $admission->fresh()->fee_schedule['rows'][0]['amount']);
        $this->assertEquals(50, $admission->fresh()->fee_schedule['rows'][0]['hostel_amount']);
        $this->assertSame('won', $deal->fresh()->status);
        $this->assertSame('2026-10-01', $deal->fresh()->closed_at->toDateString());
        $this->assertSame('won', $hostelDeal->fresh()->status);
        $this->assertEquals(0, $payment->fresh()->receipt_snapshot['due_amount']);
        $this->assertEquals(80, $payment->fresh()->allocations[0]['amount']);
        $backupCount = DB::table('first_period_conversion_backups')->count();
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $this->assertDatabaseCount('first_period_conversion_backups', $backupCount);
        $this->assertDatabaseCount('deal_payments', 2);
    }

    public function test_extending_reopens_course_preserves_receipts_and_only_charges_hostel_after_confirmed_stay(): void
    {
        extract($this->legacy());
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $receipt = $payment->fresh()->receipt_snapshot;
        $this->extend($course, [['period' => 1, 'amount' => 999], ['period' => 2, 'amount' => 200], ['period' => 3, 'amount' => 300]])->assertOk();
        $this->assertEquals(580, $deal->fresh()->value);
        $this->assertSame('open', $deal->fresh()->status);
        $this->assertNull($deal->fresh()->closed_at);
        $this->assertFalse((bool) $deal->fresh()->stage->is_won);
        $this->getJson('/api/deals/'.$deal->id)->assertOk()->assertJsonPath('data.current_period.period', 2)->assertJsonPath('data.current_period.remaining', 200);
        $this->assertSame('won', $hostelDeal->fresh()->status);
        $this->assertEquals(50, $hostelDeal->fresh()->value);
        $this->getJson('/api/deals/'.$hostelDeal->id)->assertOk()->assertJsonPath('data.current_period.period', 1);
        $this->assertFalse($admission->fresh()->fee_schedule['rows'][1]['hostel_stay']);
        $rows = $admission->fresh()->fee_schedule['rows'];
        $rows[1]['amount'] = 180;
        $rows[1]['hostel_stay'] = true;
        $rows[1]['hostel_amount'] = 60;
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $rows])->assertOk();
        $this->assertSame('open', $hostelDeal->fresh()->status);
        $this->assertEquals(110, $hostelDeal->fresh()->value);
        $this->getJson('/api/deals/'.$hostelDeal->id)->assertOk()->assertJsonPath('data.current_period.period', 2)->assertJsonPath('data.current_period.remaining', 60);
        $this->assertEquals(560, $deal->fresh()->value);
        $this->extend($course, [['period' => 1, 'amount' => 1000], ['period' => 2, 'amount' => 999], ['period' => 3, 'amount' => 999]])->assertOk();
        $this->assertEquals(560, $deal->fresh()->value);
        $this->assertEquals($receipt, $payment->fresh()->receipt_snapshot);
        $this->assertDatabaseHas('fee_audits', ['deal_id' => $deal->id, 'action' => 'course_periods_added']);
        $this->postJson('/api/deals/'.$deal->id.'/payments', ['amount' => 480, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash'])->assertCreated();
        $this->assertSame('won', $deal->fresh()->status);
        $this->assertSame(now()->toDateString(), $deal->fresh()->closed_at->toDateString());
        $existingSchedule = $admission->fresh()->fee_schedule;
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $this->assertEquals($existingSchedule, $admission->fresh()->fee_schedule);
    }

    public function test_duration_alone_does_not_charge_and_unit_change_or_truncation_is_atomic(): void
    {
        extract($this->legacy(false));
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $this->extend($course, [['period' => 1, 'amount' => 100]])->assertOk();
        $this->assertSame('won', $deal->fresh()->status);
        $this->assertSame('2026-10-01', $deal->fresh()->closed_at->toDateString());
        $this->assertCount(1, $admission->fresh()->fee_schedule['rows']);
        $this->extend($course, [['period' => 1, 'amount' => 100], ['period' => 3, 'amount' => 300]])->assertOk();
        $this->extend($course, [['period' => 1, 'amount' => 100]], 1)->assertUnprocessable();
        $this->assertEquals(3, $course->fresh()->duration_value);
        $this->putJson('/api/courses/'.$course->id, ['name' => $course->name, 'code' => $course->code, 'duration_value' => 3, 'duration_unit' => 'months', 'period_prices' => [['period' => 1, 'amount' => 100]]])->assertUnprocessable();
        $this->assertSame('years', $course->fresh()->duration_unit);
    }

    public function test_invalid_admission_is_skipped_but_course_template_converts_and_extension_remains_guarded(): void
    {
        extract($this->legacy(false, 90));
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(1);
        $this->assertEquals(1, $course->fresh()->duration_value);
        $this->assertNull($admission->fresh()->fee_schedule);
        $this->assertNull($payment->fresh()->allocations);
        $this->assertDatabaseCount('first_period_conversion_backups', 1);
        $this->extend($course, [['period' => 1, 'amount' => 100], ['period' => 2, 'amount' => 200]])->assertUnprocessable();
        $this->assertEquals(1, $course->fresh()->duration_value);
    }

    public function test_partial_payments_and_existing_receipt_snapshots_survive_conversion(): void
    {
        extract($this->legacy(false, 20));
        $snapshot = ['unit' => 'years', 'total_fee' => 80, 'total_received' => 20, 'due_amount' => 60, 'allocations' => []];
        $payment->update(['receipt_snapshot' => $snapshot]);
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $this->extend($course, [['period' => 1, 'amount' => 100], ['period' => 2, 'amount' => 200]])->assertOk();
        $this->getJson('/api/deals/'.$deal->id)->assertOk()->assertJsonPath('data.current_period.period', 1)->assertJsonPath('data.current_period.remaining', 60);
        $this->assertEquals($snapshot, $payment->fresh()->receipt_snapshot);
        $this->postJson('/api/deals/'.$deal->id.'/payments', ['amount' => 100, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash'])->assertCreated()->assertJsonPath('data.allocations.0.amount', 60)->assertJsonPath('data.allocations.1.amount', 40);
    }

    public function test_won_with_missing_payment_and_ambiguous_duration_require_manual_review(): void
    {
        extract($this->legacy(false, 20));
        $deal->update(['status' => 'won']);
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(1);
        $this->assertNull($admission->fresh()->fee_schedule);
        $deal->update(['status' => 'open']);
        $course->refresh()->update(['duration_value' => 3, 'duration_unit' => null]);
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(1);
        $this->assertNull($admission->fresh()->fee_schedule);
        $this->assertDatabaseCount('first_period_conversion_backups', 1);
    }

    public function test_existing_month_unit_and_duration_are_retained_and_only_period_one_is_converted(): void
    {
        extract($this->legacy());
        $course->update(['duration_value' => 6, 'duration_unit' => 'months', 'period_prices' => [['period' => 1, 'amount' => 100], ['period' => 2, 'amount' => 200]]]);
        $this->artisan('fees:convert-first-period --apply')->assertExitCode(0);
        $this->assertSame('months', $admission->fresh()->fee_schedule['unit']);
        $this->assertEquals(6, $admission->fresh()->fee_schedule['duration']);
        $this->assertCount(1, $admission->fresh()->fee_schedule['rows']);
        $this->assertEquals(80, $deal->fresh()->value);
        $this->assertEquals(50, $hostelDeal->fresh()->value);
    }
}
