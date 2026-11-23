<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadStatusTest extends TestCase
{
    public function test_a_planned_visit_can_be_completed_without_a_new_schedule(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $lead = $this->createLead([
            'first_name' => 'Student',
            'branch_id' => $branch->id,
            'created_by' => $super->id,
            'status' => 'meeting',
            'source' => 'manual',
        ]);
        Sanctum::actingAs($super);

        $this->putJson('/api/leads/'.$lead->id, ['status' => 'visited'])
            ->assertOk()->assertJsonPath('data.status', 'visited');

        $this->getJson('/api/leads?status=visited')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'visited']);
    }

    public function test_legacy_followup_records_remain_readable_and_can_be_updated(): void
    {
        $super = $this->createCompany();
        $branch = $this->createBranch();
        $lead = $this->createLead([
            'first_name' => 'Existing Student',
            'branch_id' => $branch->id,
            'created_by' => $super->id,
            'status' => 'followup',
            'source' => 'manual',
        ]);
        Sanctum::actingAs($super);

        $this->getJson('/api/leads/'.$lead->id)
            ->assertOk()->assertJsonPath('data.status', 'followup');
        $this->putJson('/api/leads/'.$lead->id, ['status' => 'contacted'])
            ->assertOk()->assertJsonPath('data.status', 'contacted');
    }
}
