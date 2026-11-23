<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\DealPayment;
use App\Services\PipelineSetupService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoursePriceCompatibilityTest extends TestCase
{
    private function migration(): object
    {
        return require base_path('../scl-dashboard/database/migrations/2026_11_23_000002_backfill_course_period_prices.php');
    }

    public function test_courses_without_duration_keep_single_price_on_create_and_edit(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        Sanctum::actingAs($super);
        $payload = ['branch_id' => $branch->id, 'name' => 'Single fee course', 'code' => 'SINGLE',
            'duration_value' => null, 'duration_unit' => null, 'period_prices' => null, 'price' => 60000];
        $course = $this->postJson('/api/courses', $payload)->assertCreated()
            ->assertJsonPath('data.price', 60000)->assertJsonPath('data.period_prices', null)->json('data');
        $this->putJson('/api/courses/'.$course['id'], array_merge($payload, ['price' => 65000]))
            ->assertOk()->assertJsonPath('data.price', 65000)->assertJsonPath('data.duration_value', null)
            ->assertJsonPath('data.period_prices', null);
    }

    public function test_a_duration_with_a_single_price_becomes_period_one_without_splitting_or_multiplying(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        Sanctum::actingAs($super);
        foreach (['years', 'months', 'weeks', 'days'] as $unit) {
            $this->postJson('/api/courses', [
                'branch_id' => $branch->id, 'name' => 'Course '.$unit, 'code' => 'C-'.$unit,
                'duration_value' => 3, 'duration_unit' => $unit, 'price' => 25000,
            ])->assertCreated()->assertJsonPath('data.price', 25000)
                ->assertJsonPath('data.period_prices.0.period', 1)->assertJsonPath('data.period_prices.0.amount', 25000)
                ->assertJsonCount(1, 'data.period_prices');
        }
    }

    public function test_backfill_is_repeat_safe_preserves_students_and_skips_unconfigured_or_already_priced_courses(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $course = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 3, 'duration_unit' => 'years', 'price' => 25000]);
        $plain = $this->createCourse(['branch_id' => $branch->id, 'price' => 60000]);
        $configured = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 3, 'duration_unit' => 'years',
            'price' => 400, 'period_prices' => [['period' => 1, 'amount' => 100], ['period' => 3, 'amount' => 300]]]);
        $missingUnit = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 3, 'price' => 500]);
        $free = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 6, 'duration_unit' => 'months', 'price' => 0]);
        $deleted = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 2, 'duration_unit' => 'years', 'price' => 1000]);
        $deleted->delete();
        $lead = $this->createLead(['branch_id' => $branch->id, 'course_id' => $course->id, 'created_by' => $super->id,
            'first_name' => 'Existing student', 'status' => 'converted']);
        $admission = Admission::create(['lead_id' => $lead->id, 'branch_id' => $branch->id, 'course_id' => $course->id,
            'admission_date' => now()->toDateString(), 'status' => 'pending', 'enrollment_id' => 'EXISTING-1']);
        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($branch->id);
        $deal = $this->createDeal(['branch_id' => $branch->id, 'lead_id' => $lead->id, 'created_by' => $super->id,
            'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->first()->id, 'title' => 'Existing whole course', 'value' => 25000, 'status' => 'open']);
        $payment = DealPayment::create(['branch_id' => $branch->id, 'deal_id' => $deal->id, 'created_by' => $super->id,
            'amount' => 5000, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash']);
        $before = [$lead->fresh()->toArray(), $admission->fresh()->toArray(), $deal->fresh()->toArray(), $payment->fresh()->toArray()];
        $updatedAt = $course->updated_at;
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertEquals([['period' => 1, 'amount' => 25000]], $course->fresh()->period_prices);
        $this->assertEquals(25000, $course->fresh()->price);
        $this->assertEquals($updatedAt, $course->fresh()->updated_at);
        $this->assertNull($plain->fresh()->period_prices);
        $this->assertNull($missingUnit->fresh()->period_prices);
        $this->assertNull($deleted->fresh()->period_prices);
        $this->assertEquals($configured->period_prices, $configured->fresh()->period_prices);
        $this->assertEquals([['period' => 1, 'amount' => 0]], $free->fresh()->period_prices);
        $this->assertDatabaseCount('course_period_price_backups', 2);
        $this->assertEquals($before, [$lead->fresh()->toArray(), $admission->fresh()->toArray(), $deal->fresh()->toArray(), $payment->fresh()->toArray()]);
        $this->assertDatabaseHas('course_period_price_backups', ['course_id' => $course->id, 'original_price' => 25000]);
    }

    public function test_rollback_restores_only_untouched_courses_and_keeps_backups_for_edited_courses(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $untouched = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 3, 'duration_unit' => 'years', 'price' => 25000]);
        $edited = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 2, 'duration_unit' => 'years', 'price' => 50000]);
        $migration = $this->migration();
        $migration->up();
        $edited->update(['period_prices' => [['period' => 1, 'amount' => 20000], ['period' => 2, 'amount' => 30000]]]);
        $migration->down();
        $this->assertNull($untouched->fresh()->period_prices);
        $this->assertEquals(25000, $untouched->fresh()->price);
        $this->assertCount(2, $edited->fresh()->period_prices);
        $this->assertSame(1, DB::table('course_period_price_backups')->count());
        $this->assertDatabaseHas('course_period_price_backups', ['course_id' => $edited->id]);
    }

    public function test_removing_duration_restores_single_price_mode(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $course = $this->createCourse(['branch_id' => $branch->id, 'duration_value' => 3, 'duration_unit' => 'years',
            'price' => 25000, 'period_prices' => [['period' => 1, 'amount' => 25000]]]);
        Sanctum::actingAs($super);
        $this->putJson('/api/courses/'.$course->id, [
            'name' => $course->name, 'code' => $course->code, 'duration_value' => null, 'duration_unit' => null,
            'period_prices' => null, 'price' => 25000,
        ])->assertOk()->assertJsonPath('data.period_prices', null)->assertJsonPath('data.price', 25000);
    }
}
