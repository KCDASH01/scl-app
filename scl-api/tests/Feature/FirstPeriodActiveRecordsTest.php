<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\DealPayment;
use App\Services\FeeScheduleService;
use App\Services\FirstPeriodConversionService;
use App\Services\PipelineSetupService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FirstPeriodActiveRecordsTest extends TestCase
{
    private function student(?array $base = null, bool $hostel = false): array
    {
        if (! $base) {
            $super = $this->createCompany();
            $branch = $this->createBranch();
            $course = $this->createCourse(['branch_id' => $branch->id, 'price' => 100, 'duration_value' => null, 'duration_unit' => null]);
            $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($branch->id);
        } else {
            extract($base, EXTR_SKIP);
        }
        $lead = $this->createLead(['first_name' => 'Historical student', 'branch_id' => $branch->id, 'course_id' => $course->id, 'created_by' => $super->id, 'status' => 'converted']);
        $admission = Admission::create(['branch_id' => $branch->id, 'lead_id' => $lead->id, 'course_id' => $course->id, 'enrollment_id' => 'HISTORY-'.uniqid(), 'status' => 'pending', 'hostel_stay' => $hostel, 'hostel_amount' => $hostel ? 50 : null]);
        $fields = ['branch_id' => $branch->id, 'lead_id' => $lead->id, 'created_by' => $super->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->where('is_won', true)->firstOrFail()->id, 'status' => 'won', 'closed_at' => '2026-10-01'];
        $deal = $this->createDeal($fields + ['fee_kind' => 'course', 'title' => 'Course', 'value' => 80]);
        $payment = DealPayment::create(['branch_id' => $branch->id, 'deal_id' => $deal->id, 'created_by' => $super->id, 'amount' => 80, 'payment_date' => '2026-10-01', 'payment_mode' => 'upi', 'txn_or_utr_number' => 'HISTORICAL-REF', 'notes' => 'Original notes']);
        $hostelDeal = null;
        if ($hostel) {
            $hostelDeal = $this->createDeal($fields + ['fee_kind' => 'hostel', 'title' => 'Hostel', 'value' => 50]);
            DealPayment::create(['branch_id' => $branch->id, 'deal_id' => $hostelDeal->id, 'created_by' => $super->id, 'amount' => 50, 'payment_date' => '2026-10-01', 'payment_mode' => 'cash']);
        }

        $payment->refresh();

        return compact('super', 'branch', 'course', 'pipeline', 'lead', 'admission', 'deal', 'payment', 'hostelDeal');
    }

    private function runConversion(bool $apply = true, int $exit = 0): array
    {
        $this->assertSame($exit, Artisan::call('fees:convert-first-period', $apply ? ['--apply' => true] : []));
        $output = Artisan::output();
        $this->assertSame(1, preg_match('/^SUMMARY (.+)$/m', $output, $matches));
        $summary = json_decode(trim($matches[1]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($summary['payment_counts_and_totals_unchanged']);

        return ['summary' => $summary, 'output' => $output, 'counts' => $summary['counts']];
    }

    private function databaseSnapshot(): array
    {
        return collect(['courses', 'leads', 'admissions', 'deals', 'deal_payments', 'first_period_conversion_backups', 'fee_audits'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_mysql_preflight_refuses_nontransactional_or_missing_tables_without_schema_writes(): void
    {
        $original = DB::getFacadeRoot();
        $connection = \Mockery::mock(Connection::class);
        $builder = \Mockery::mock(Builder::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('isolated_metadata_fixture');
        $connection->shouldReceive('table')->with('information_schema.TABLES')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('TABLE_SCHEMA', 'isolated_metadata_fixture')->once()->andReturnSelf();
        $builder->shouldReceive('whereIn')->once()->andReturnSelf();
        $builder->shouldReceive('pluck')->with('ENGINE', 'TABLE_NAME')->once()->andReturn(collect([
            'courses' => 'InnoDB', 'leads' => 'InnoDB', 'admissions' => 'MyISAM',
        ]));
        DB::shouldReceive('connection')->once()->andReturn($connection);
        try {
            (new FirstPeriodConversionService)->assertTransactionalStorage();
            $this->fail('Nontransactional tables must abort before conversion.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('admissions', $e->getMessage());
            $this->assertStringContainsString('InnoDB', $e->getMessage());
        } finally {
            DB::swap($original);
        }
    }

    public function test_deleted_and_missing_leads_are_excluded_without_changing_their_records(): void
    {
        $deleted = $this->student();
        $deleted['lead']->delete();
        $missing = $this->student($deleted);
        // Model an orphan in the isolated SQLite database, without deleting any lead.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('admissions')->where('id', $missing['admission']->id)->update(['lead_id' => 999999]);
        $valid = $this->student($deleted);
        $rawLead = DB::table('leads')->where('id', $deleted['lead']->id)->first();
        $rawPayment = $deleted['payment']->getRawOriginal();
        $report = $this->runConversion();
        $this->assertSame(3, $report['counts']['admissions_examined']);
        $this->assertSame(2, $report['counts']['admissions_excluded_lead']);
        $this->assertSame(1, $report['counts']['admissions_converted']);
        $this->assertNotNull($valid['admission']->fresh()->fee_schedule);
        $this->assertNull($deleted['admission']->fresh()->fee_schedule);
        $this->assertNull($missing['admission']->fresh()->fee_schedule);
        $this->assertEquals($rawLead, DB::table('leads')->where('id', $deleted['lead']->id)->first());
        $this->assertEquals($rawPayment, $deleted['payment']->fresh()->getRawOriginal());
    }

    public function test_deleted_admissions_and_courses_are_not_examined_or_updated(): void
    {
        $student = $this->student();
        $student['admission']->delete();
        $before = DB::table('admissions')->first();
        $payment = $student['payment']->getRawOriginal();
        $report = $this->runConversion();
        $this->assertSame(0, $report['counts']['admissions_examined']);
        $this->assertEquals($before, DB::table('admissions')->first());
        $this->assertEquals($payment, $student['payment']->fresh()->getRawOriginal());
        $student['course']->delete();
        $snapshot = $this->databaseSnapshot();
        $report = $this->runConversion();
        $this->assertSame(0, $report['counts']['courses_examined']);
        $this->assertEquals($snapshot, $this->databaseSnapshot());
    }

    public function test_deleted_course_with_payments_is_never_restored_or_replaced(): void
    {
        $student = $this->student();
        $student['deal']->delete();
        $deal = DB::table('deals')->first();
        $payment = $student['payment']->getRawOriginal();
        $report = $this->runConversion(exit: 1);
        $this->assertSame(1, $report['counts']['admissions_skipped_financial']);
        $this->assertStringContainsString('Deleted course deal', $report['output']);
        $this->assertEquals($deal, DB::table('deals')->first());
        $this->assertEquals($payment, $student['payment']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('deals', 1);
        $this->assertNull($student['admission']->fresh()->fee_schedule);
        $this->assertDatabaseCount('fee_audits', 0);
        $replacement = $student['deal']->replicate();
        $replacement->deleted_at = null;
        $replacement->status = 'open';
        $replacement->save();
        $this->runConversion(exit: 1);
        $this->assertNull($replacement->fresh()->fee_periods);
        $this->assertEquals($payment, $student['payment']->fresh()->getRawOriginal());
    }

    public function test_deleted_hostel_payments_and_active_replacement_remain_untouched(): void
    {
        $student = $this->student(hostel: true);
        $student['hostelDeal']->delete();
        $beforeDeals = DB::table('deals')->orderBy('id')->get()->toJson();
        $beforePayments = DB::table('deal_payments')->orderBy('id')->get()->toJson();
        $report = $this->runConversion(exit: 1);
        $this->assertStringContainsString('Deleted hostel deal', $report['output']);
        $this->assertSame(['count' => 2, 'total' => '130.00'], $report['summary']['payments_before']);
        $this->assertSame($beforeDeals, DB::table('deals')->orderBy('id')->get()->toJson());
        $this->assertSame($beforePayments, DB::table('deal_payments')->orderBy('id')->get()->toJson());
        $replacement = $student['hostelDeal']->replicate();
        $replacement->deleted_at = null;
        $replacement->status = 'open';
        $replacement->save(); // Test fixture only: conversion must never perform this action.
        $beforeDeals = DB::table('deals')->orderBy('id')->get()->toJson();
        $this->runConversion(exit: 1);
        $this->assertSame($beforeDeals, DB::table('deals')->orderBy('id')->get()->toJson());
        $this->assertSame($beforePayments, DB::table('deal_payments')->orderBy('id')->get()->toJson());
        $this->assertNull($replacement->fresh()->fee_periods);
    }

    public function test_invalid_admissions_do_not_block_valid_students_and_can_be_reviewed_and_retried(): void
    {
        $missing = $this->student();
        // A deleted unpaid fee does not qualify as an active course fee.
        $missing['deal']->delete();
        // Retain its payment: it is ambiguous and must be skipped independently.
        $duplicate = $this->student($missing);
        $duplicate['deal']->replicate()->save();
        $reconcile = $this->student($missing);
        $reconcile['deal']->update(['value' => 79]);
        $valid = $this->student($missing, hostel: true);
        $report = $this->runConversion(exit: 1);
        $this->assertSame(4, $report['counts']['admissions_examined']);
        $this->assertSame(3, $report['counts']['admissions_skipped_financial']);
        $this->assertSame(1, $report['counts']['eligible_admissions']);
        $this->assertSame(1, $report['counts']['course_deals_converted']);
        $this->assertSame(1, $report['counts']['hostel_deals_converted']);
        $this->assertSame(2, $report['counts']['payment_allocations_generated']);
        $this->assertNull($missing['admission']->fresh()->fee_schedule);
        $this->assertNull($duplicate['admission']->fresh()->fee_schedule);
        $this->assertNotNull($valid['admission']->fresh()->fee_schedule);
        // Legitimate correction of a fixture's erroneous fee value, not a payment rewrite.
        $reconcile['deal']->update(['value' => 80]);
        $report = $this->runConversion(exit: 1);
        $this->assertSame(1, $report['counts']['admissions_already_converted']);
        $this->assertSame(1, $report['counts']['admissions_converted']);
        $this->assertNotNull($reconcile['admission']->fresh()->fee_schedule);
    }

    public function test_missing_course_missing_hostel_duplicate_hostel_and_mixed_schedules_are_reported(): void
    {
        $missing = $this->student();
        $otherLead = $this->createLead(['first_name' => 'Other relationship', 'branch_id' => $missing['branch']->id, 'created_by' => $missing['super']->id]);
        // Fixture admission has no associated fee; keep original payments on their original deal.
        $missing['admission']->update(['lead_id' => $otherLead->id]);
        $hostel = $this->student($missing);
        $hostel['admission']->update(['hostel_stay' => true, 'hostel_amount' => 50]);
        $duplicate = $this->student($missing, hostel: true);
        $duplicate['hostelDeal']->replicate()->save();
        $mixed = $this->student($missing);
        $mixed['deal']->update(['fee_unit' => 'years']);
        $report = $this->runConversion(exit: 1);
        $this->assertSame(4, $report['counts']['admissions_skipped_financial']);
        foreach (['Missing active course fee deal', 'Hostel stay requires', 'Duplicate active hostel fee deals', 'mixed schedules'] as $reason) {
            $this->assertStringContainsString($reason, $report['output']);
        }
        $this->assertDatabaseCount('fee_audits', 0);
    }

    public function test_preview_rolls_back_every_table_even_with_valid_and_invalid_students(): void
    {
        $valid = $this->student(hostel: true);
        $invalid = $this->student($valid);
        $invalid['deal']->update(['value' => 70]);
        $snapshot = $this->databaseSnapshot();
        $report = $this->runConversion(apply: false, exit: 1);
        $this->assertSame(1, $report['counts']['admissions_converted']);
        $this->assertSame(1, $report['counts']['admissions_skipped_financial']);
        $this->assertSame($snapshot, $this->databaseSnapshot());
    }

    public function test_expected_failure_after_payment_writes_rolls_back_entire_admission_then_continues(): void
    {
        $broken = $this->student(hostel: true);
        $valid = $this->student($broken);
        $real = new FeeScheduleService;
        $this->partialMock(FeeScheduleService::class, function ($mock) use ($real, $broken) {
            $mock->shouldReceive('setDealSchedule')->andReturnUsing(function ($deal, $schedule, $rows) use ($real, $broken) {
                if ($deal->id === $broken['hostelDeal']->id) {
                    throw ValidationException::withMessages(['conversion' => 'Simulated record-level failure after course payment and audit writes.']);
                }
                $real->setDealSchedule($deal, $schedule, $rows);
            });
        });
        $payments = DB::table('deal_payments')->whereIn('deal_id', [$broken['deal']->id, $broken['hostelDeal']->id])->get()->toJson();
        $this->runConversion(exit: 1);
        $this->assertNull($broken['admission']->fresh()->fee_schedule);
        $this->assertNull($broken['deal']->fresh()->fee_periods);
        $this->assertSame($payments, DB::table('deal_payments')->whereIn('deal_id', [$broken['deal']->id, $broken['hostelDeal']->id])->get()->toJson());
        $this->assertDatabaseMissing('fee_audits', ['deal_id' => $broken['deal']->id]);
        $this->assertDatabaseMissing('first_period_conversion_backups', ['entity' => 'admissions', 'record_id' => $broken['admission']->id]);
        $this->assertNotNull($valid['admission']->fresh()->fee_schedule);
    }

    public function test_unexpected_database_error_aborts_and_rolls_back_current_admission(): void
    {
        $broken = $this->student(hostel: true);
        $later = $this->student($broken);
        DB::unprepared("CREATE TRIGGER fail_conversion_audit BEFORE INSERT ON fee_audits BEGIN SELECT RAISE(ABORT, 'simulated database failure'); END");
        try {
            Artisan::call('fees:convert-first-period', ['--apply' => true]);
            $this->fail('Unexpected database failures must abort.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('simulated database failure', $e->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER fail_conversion_audit');
        }
        $this->assertNull($broken['admission']->fresh()->fee_schedule);
        $this->assertNull($broken['payment']->fresh()->allocations);
        $this->assertNull($later['admission']->fresh()->fee_schedule);
        $this->assertDatabaseCount('fee_audits', 0);
        $this->assertDatabaseCount('first_period_conversion_backups', 1); // Course template committed separately.
    }

    public function test_repeated_execution_preserves_raw_payment_fields_receipts_and_backup_audit_counts(): void
    {
        $student = $this->student(hostel: true);
        $student['payment']->update(['receipt_snapshot' => []]);
        $original = $student['payment']->getRawOriginal();
        $this->runConversion();
        $current = $student['payment']->fresh()->getRawOriginal();
        foreach (['id', 'deal_id', 'branch_id', 'created_by', 'amount', 'payment_date', 'payment_mode', 'txn_or_utr_number', 'notes', 'created_at', 'receipt_snapshot'] as $field) {
            $this->assertSame($original[$field], $current[$field]);
        }
        $snapshot = $this->databaseSnapshot();
        $report = $this->runConversion();
        $this->assertSame(1, $report['counts']['admissions_already_converted']);
        $this->assertSame(0, $report['counts']['payment_allocations_generated']);
        $this->assertSame($snapshot, $this->databaseSnapshot());
        $this->runConversion(apply: false);
        $this->assertSame($snapshot, $this->databaseSnapshot());
    }

    public function test_invalid_existing_allocations_and_duplicate_admission_relationships_are_not_repaired(): void
    {
        $student = $this->student();
        $student['payment']->update(['allocations' => [['period' => 1, 'amount' => 999]]]);
        $report = $this->runConversion(exit: 1);
        $this->assertStringContainsString('existing allocations', $report['output']);
        $this->assertEquals(999, $student['payment']->fresh()->allocations[0]['amount']);
        // Simulate a legacy schema missing the current uniqueness constraint, in SQLite only.
        DB::statement('DROP INDEX admissions_lead_id_unique');
        $duplicate = $student['admission']->replicate();
        $duplicate->enrollment_id = 'DUPLICATE-RELATIONSHIP';
        $duplicate->save();
        $report = $this->runConversion(exit: 1);
        $this->assertSame(2, $report['counts']['admissions_skipped_financial']);
        $this->assertStringContainsString('Duplicate active admission relationships', $report['output']);
    }

    public function test_deleted_unpaid_deals_do_not_prevent_an_unambiguous_active_fee_conversion(): void
    {
        $student = $this->student();
        $unpaid = $student['deal']->replicate();
        $unpaid->status = 'open';
        $unpaid->save();
        $unpaid->delete();
        $original = DB::table('deals')->where('id', $unpaid->id)->first();
        $this->runConversion();
        $this->assertNotNull($student['admission']->fresh()->fee_schedule);
        $this->assertEquals($original, DB::table('deals')->where('id', $unpaid->id)->first());
        $this->assertDatabaseCount('deals', 2);
    }

    public function test_invalid_amounts_and_corrupt_already_converted_allocations_are_reported_without_repair(): void
    {
        $badFee = $this->student();
        $badFee['deal']->update(['value' => -1]);
        $badPayment = $this->student($badFee);
        $badPayment['payment']->update(['amount' => 0]); // Invalid historical fixture, never a converter operation.
        $converted = $this->student($badFee);
        $report = $this->runConversion(exit: 1);
        $this->assertSame(2, $report['counts']['admissions_skipped_financial']);
        $this->assertSame(1, $report['counts']['admissions_converted']);
        $converted['payment']->refresh()->update(['allocations' => [['period' => 1, 'amount' => 999, 'remaining' => 0]]]);
        $snapshot = $this->databaseSnapshot();
        $report = $this->runConversion(exit: 1);
        $this->assertSame(3, $report['counts']['admissions_skipped_financial']);
        $this->assertStringContainsString('existing allocations do not reconcile', $report['output']);
        $this->assertSame($snapshot, $this->databaseSnapshot());
    }

    public function test_unexpected_failure_in_preview_rolls_back_course_and_all_prior_simulated_admissions(): void
    {
        $first = $this->student();
        $second = $this->student($first);
        DB::unprepared('CREATE TRIGGER fail_second_audit BEFORE INSERT ON fee_audits WHEN NEW.deal_id = '.$second['deal']->id." BEGIN SELECT RAISE(ABORT, 'simulated preview failure'); END");
        $snapshot = $this->databaseSnapshot();
        try {
            Artisan::call('fees:convert-first-period');
            $this->fail('Database failures must abort preview.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('simulated preview failure', $e->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER fail_second_audit');
        }
        $this->assertSame($snapshot, $this->databaseSnapshot());
    }

    public function test_course_validation_blocks_its_admissions_without_writes_and_reports_ids(): void
    {
        $student = $this->student();
        $student['course']->update(['duration_value' => 3, 'duration_unit' => null]);
        $snapshot = $this->databaseSnapshot();
        $report = $this->runConversion(exit: 1);
        $this->assertSame(1, $report['counts']['courses_skipped']);
        $this->assertSame(1, $report['counts']['admissions_examined']);
        $this->assertSame(1, $report['counts']['admissions_blocked_course']);
        $this->assertStringContainsString('Admissions blocked by this course: ['.$student['admission']->id.']', $report['output']);
        $this->assertSame($snapshot, $this->databaseSnapshot());
    }
}
