<?php

namespace Tests\Feature;

use App\Models\DealPayment;
use App\Services\PipelineSetupService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DealPaymentAccessTest extends TestCase
{
    public static function feeKinds(): array
    {
        return [['course'], ['hostel']];
    }

    #[DataProvider('feeKinds')]
    public function test_only_management_can_edit_transactions(string $feeKind): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $employee = $this->createUser([
            'email' => 'cashier@acme.test', 'role' => 'employee', 'branch_id' => $branch->id,
        ]);
        $pipeline = app(PipelineSetupService::class)->createDefaultPipeline($branch->id);
        $deal = $this->createDeal([
            'branch_id' => $branch->id, 'created_by' => $super->id,
            'assigned_to' => $employee->id, 'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->first()->id,
            'title' => 'Student fees', 'fee_kind' => $feeKind,
            'value' => 10000, 'status' => 'open',
        ]);
        $payment = DealPayment::create([
            'branch_id' => $branch->id, 'deal_id' => $deal->id,
            'created_by' => $employee->id, 'amount' => 100,
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash',
        ]);
        $url = '/api/deals/'.$deal->id.'/payments';
        $payload = [
            'amount' => 250, 'payment_date' => now()->toDateString(),
            'payment_mode' => 'upi', 'notes' => 'Corrected receipt',
        ];

        Sanctum::actingAs($employee);
        $this->putJson($url.'/'.$payment->id, $payload)->assertForbidden();
        $this->assertDatabaseHas('deal_payments', [
            'id' => $payment->id, 'amount' => 100, 'payment_mode' => 'cash', 'notes' => null,
        ]);
        $this->getJson($url)->assertOk();
        $this->postJson($url, $payload)->assertCreated();

        foreach (['admin', 'manager'] as $role) {
            $manager = $this->createUser([
                'email' => $role.'@acme.test', 'role' => $role, 'branch_id' => $branch->id,
            ]);
            Sanctum::actingAs($manager);
            $this->putJson($url.'/'.$payment->id, $payload)
                ->assertOk()->assertJsonPath('data.payment_mode', 'upi');
        }

        Sanctum::actingAs($super);
        $this->putJson($url.'/'.$payment->id, array_merge($payload, ['amount' => 300]))->assertOk();
        $this->assertDatabaseHas('deal_payments', ['id' => $payment->id, 'amount' => 300]);

        $otherBranch = $this->createBranch();
        $otherManager = $this->createUser([
            'email' => 'other.manager@acme.test', 'role' => 'manager', 'branch_id' => $otherBranch->id,
        ]);
        Sanctum::actingAs($otherManager);
        $this->putJson($url.'/'.$payment->id, $payload)->assertNotFound();
        $this->assertDatabaseHas('deal_payments', ['id' => $payment->id, 'amount' => 300]);
    }
}
