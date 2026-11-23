<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Services\PipelineSetupService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitFeeScheduleTest extends TestCase
{
    private function fixture(array $prices = [], int $duration = 3, string $unit = 'years'): array
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $employee = $this->createUser(['email' => 'fees@acme.test', 'role' => 'employee', 'branch_id' => $branch->id]);
        $prices = $prices ?: [['period' => 1, 'amount' => 100], ['period' => 2, 'amount' => 200], ['period' => 3, 'amount' => 300]];
        $course = $this->createCourse([
            'branch_id' => $branch->id, 'duration_value' => $duration, 'duration_unit' => $unit,
            'period_prices' => $prices, 'price' => array_sum(array_column($prices, 'amount')),
        ]);
        $lead = $this->createLead([
            'branch_id' => $branch->id, 'course_id' => $course->id, 'created_by' => $super->id,
            'assigned_to' => $employee->id, 'first_name' => 'Student', 'status' => 'new',
        ]);
        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($branch->id);
        Sanctum::actingAs($employee);
        $payload = ['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->first()->id, 'title' => 'Student fees'];

        return compact('super', 'branch', 'employee', 'course', 'lead', 'payload');
    }

    private function convert(array $data, array $extra = []): Deal
    {
        $this->postJson('/api/leads/'.$data['lead']->id.'/convert', array_merge($data['payload'], $extra))->assertCreated();

        return Deal::where('lead_id', $data['lead']->id)->where('fee_kind', 'course')->firstOrFail();
    }

    private function pay(Deal $deal, float $amount)
    {
        return $this->postJson('/api/deals/'.$deal->id.'/payments', [
            'amount' => $amount, 'payment_date' => now()->toDateString(), 'payment_mode' => 'upi',
        ]);
    }

    public function test_course_prices_validate_periods_and_compute_total_without_changing_existing_admissions(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data);
        Sanctum::actingAs($data['super']);
        $payload = $data['course']->only(['branch_id', 'name', 'code', 'duration_value', 'duration_unit']);
        $this->putJson('/api/courses/'.$data['course']->id, $payload + ['period_prices' => [
            ['period' => 1, 'amount' => 500], ['period' => 3, 'amount' => 700],
        ]])->assertOk()->assertJsonPath('data.price', 1200);
        $this->assertEquals(600, $deal->fresh()->value);
        $this->assertCount(3, Admission::first()->fee_schedule['rows']);
        foreach ([
            [['period' => 4, 'amount' => 100]],
            [['period' => 1, 'amount' => 100], ['period' => 1, 'amount' => 200]],
            [['period' => 1, 'amount' => -1]],
            [['period' => 1.5, 'amount' => 100]],
        ] as $rows) {
            $this->putJson('/api/courses/'.$data['course']->id, $payload + ['period_prices' => $rows])->assertUnprocessable();
        }
        $this->putJson('/api/courses/'.$data['course']->id, array_merge($payload, ['duration_value' => 2, 'price' => 999]))->assertUnprocessable();
    }

    public function test_payment_spills_across_periods_then_closes_and_preserves_receipts(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data);
        $first = $this->pay($deal, 350)->assertCreated()
            ->assertJsonPath('data.allocations.0.amount', 100)
            ->assertJsonPath('data.allocations.1.amount', 200)
            ->assertJsonPath('data.allocations.2.amount', 50)
            ->assertJsonPath('data.receipt_snapshot.due_amount', 250)->json('data');
        $this->getJson('/api/deals/'.$deal->id)->assertOk()
            ->assertJsonPath('data.current_period.period', 3)->assertJsonPath('data.current_period.remaining', 250);
        $this->pay($deal, 250)->assertCreated()->assertJsonPath('deal_status', 'won');
        $this->pay($deal, 1)->assertUnprocessable();
        $this->assertEquals($first['receipt_snapshot'], DealPayment::find($first['id'])->receipt_snapshot);
        $this->assertSame(2, DealPayment::count());
    }

    public function test_overpayment_and_initial_overpayment_roll_back_completely(): void
    {
        $data = $this->fixture();
        $this->postJson('/api/leads/'.$data['lead']->id.'/convert', $data['payload'] + [
            'payment' => ['amount' => 601, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('admissions', 0);
        $this->assertDatabaseCount('deal_payments', 0);
        $this->assertSame('new', $data['lead']->fresh()->status);
        $deal = $this->convert($data);
        $this->pay($deal, 601)->assertUnprocessable();
        $this->assertDatabaseCount('deal_payments', 0);
    }

    public function test_monthly_gaps_follow_configured_order_and_cent_precision(): void
    {
        $data = $this->fixture([
            ['period' => 1, 'amount' => 0.1], ['period' => 2, 'amount' => 0.2],
            ['period' => 5, 'amount' => 0.3], ['period' => 6, 'amount' => 0.4],
        ], 6, 'months');
        $deal = $this->convert($data);
        $this->pay($deal, 0.65)->assertCreated()->assertJsonPath('data.allocations.3.period', 6)
            ->assertJsonPath('data.allocations.3.amount', 0.05);
        $this->getJson('/api/deals/'.$deal->id)->assertOk()->assertJsonPath('data.fee_unit', 'months')
            ->assertJsonPath('data.current_period.period', 6)->assertJsonPath('data.current_period.remaining', 0.35);
    }

    public function test_employee_can_set_student_prices_and_hostel_stay_per_period(): void
    {
        $data = $this->fixture();
        $courseDeal = $this->convert($data);
        $admission = Admission::first();
        $rows = [
            ['period' => 1, 'amount' => 110, 'hostel_stay' => true, 'hostel_amount' => 50],
            ['period' => 2, 'amount' => 210, 'hostel_stay' => false, 'hostel_amount' => null],
            ['period' => 3, 'amount' => 310, 'hostel_stay' => true, 'hostel_amount' => 100],
        ];
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $rows])->assertOk()
            ->assertJsonPath('data.hostel_amount', 150)->assertJsonPath('data.fee_schedule.rows.0.amount', 110);
        $hostelDeal = Deal::where('fee_kind', 'hostel')->firstOrFail();
        $this->assertEquals(630, $courseDeal->fresh()->value);
        $this->pay($hostelDeal, 75)->assertCreated()->assertJsonPath('data.allocations.1.period', 3)
            ->assertJsonPath('data.allocations.1.amount', 25);
        $this->assertEquals(0, $courseDeal->payments()->sum('amount'));
        $bad = $rows;
        $bad[0]['hostel_amount'] = null;
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $bad])->assertUnprocessable();
        $this->assertEquals(150, $admission->fresh()->hostel_amount);
    }

    public function test_paid_period_price_changes_require_management_and_reason_and_cannot_erase_payments(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data);
        $this->pay($deal, 50)->assertCreated();
        $admission = Admission::first();
        $rows = $admission->fee_schedule['rows'];
        $rows[0]['amount'] = 120;
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $rows])->assertForbidden();
        Sanctum::actingAs($data['super']);
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $rows])->assertUnprocessable();
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $rows, 'fee_change_reason' => 'Agreed correction'])->assertOk();
        $this->assertEquals(620, $deal->fresh()->value);
        $rows = array_map(fn ($r) => array_merge($r, ['amount' => 0]), $rows);
        $this->putJson('/api/admissions/'.$admission->id, ['fee_periods' => $rows, 'fee_change_reason' => 'Correction'])->assertUnprocessable();
        $this->assertEquals(620, $deal->fresh()->value);
        $this->assertEquals(50, $deal->payments()->sum('amount'));
    }

    public function test_payment_correction_reallocates_preserves_original_snapshot_and_records_audit(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data);
        $one = $this->pay($deal, 300)->assertCreated()->json('data');
        $two = $this->pay($deal, 300)->assertCreated()->json('data');
        Sanctum::actingAs($data['super']);
        $this->putJson('/api/deals/'.$deal->id.'/payments/'.$one['id'], [
            'amount' => 50, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'notes' => 'Corrected entry',
        ])->assertOk()->assertJsonPath('deal_reopened', true);
        $second = DealPayment::find($two['id']);
        $this->assertEquals([1, 2, 3], array_column($second->allocations, 'period'));
        $this->assertEquals($two['receipt_snapshot'], $second->receipt_snapshot);
        $this->getJson('/api/deals/'.$deal->id.'/payments')->assertOk()
            ->assertJsonPath('history.0.action', 'payment_corrected')
            ->assertJsonPath('history.0.reason', 'Corrected entry');
        $this->assertDatabaseHas('fee_audits', ['payment_id' => $one['id']]);
    }

    public function test_generic_deal_edits_cannot_bypass_the_schedule(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data);
        Sanctum::actingAs($data['super']);
        $this->putJson('/api/deals/'.$deal->id, [
            'title' => $deal->title, 'pipeline_id' => $deal->pipeline_id,
            'stage_id' => $deal->stage_id, 'value' => 1,
        ])->assertUnprocessable();
        $this->deleteJson('/api/deals/'.$deal->id)->assertUnprocessable();
        $this->patchJson('/api/deals/'.$deal->id.'/stage', ['stage_id' => $deal->stage_id])->assertUnprocessable();
        $this->assertEquals(600, $deal->fresh()->value);
    }

    public function test_initial_payment_is_allocated_and_custom_prices_are_snapshotted(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data, [
            'fee_periods' => [['period' => 1, 'amount' => 20], ['period' => 2, 'amount' => 30], ['period' => 3, 'amount' => 40]],
            'payment' => ['amount' => 60, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash'],
        ]);
        $this->assertEquals(90, $deal->value);
        $this->assertEquals([20, 30, 10], array_column($deal->payments()->first()->allocations, 'amount'));
        $this->assertEquals(600, $data['course']->fresh()->price);
    }

    public function test_course_selection_replaces_only_unpaid_schedules_and_uses_new_unit(): void
    {
        $data = $this->fixture();
        $deal = $this->convert($data);
        $admission = Admission::first();
        $other = $this->createCourse([
            'branch_id' => $data['branch']->id, 'duration_value' => 6, 'duration_unit' => 'months',
            'period_prices' => [['period' => 1, 'amount' => 100], ['period' => 6, 'amount' => 200]], 'price' => 300,
        ]);
        $rows = [
            ['period' => 1, 'amount' => 110, 'hostel_stay' => false, 'hostel_amount' => null],
            ['period' => 6, 'amount' => 220, 'hostel_stay' => true, 'hostel_amount' => 50],
        ];
        $this->putJson('/api/admissions/'.$admission->id, ['course_id' => $other->id, 'fee_periods' => $rows])
            ->assertOk()->assertJsonPath('data.fee_schedule.unit', 'months');
        $this->assertEquals(330, $deal->fresh()->value);
        $this->assertSame('months', $deal->fresh()->fee_unit);
        $this->assertSame($other->id, $data['lead']->fresh()->course_id);
        $this->pay($deal, 10)->assertCreated();
        $this->putJson('/api/admissions/'.$admission->id, ['course_id' => $data['course']->id])->assertUnprocessable();
        $this->assertSame($other->id, $admission->fresh()->course_id);
    }

    public function test_legacy_whole_course_records_are_not_silently_migrated(): void
    {
        $data = $this->fixture();
        $data['course']->update(['period_prices' => null]);
        $deal = $this->convert($data);
        $this->pay($deal, 50)->assertCreated();
        $admission = Admission::first();
        $this->assertNull($admission->fee_schedule);
        $data['course']->update(['period_prices' => [['period' => 1, 'amount' => 900]], 'price' => 900]);
        $this->putJson('/api/admissions/'.$admission->id, [
            'fee_periods' => [['period' => 1, 'amount' => 900, 'hostel_stay' => false]],
        ])->assertUnprocessable();
        $this->assertNull($deal->fresh()->fee_periods);
        $this->assertEquals(600, $deal->fresh()->value);
        $this->assertEquals(50, $deal->payments()->sum('amount'));
    }

    public function test_admission_overrides_cannot_remove_configured_periods(): void
    {
        $data = $this->fixture();
        $this->postJson('/api/leads/'.$data['lead']->id.'/convert', $data['payload'] + [
            'fee_periods' => [['period' => 3, 'amount' => 1]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('admissions', 0);
    }
}
