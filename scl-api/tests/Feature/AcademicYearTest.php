<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    public function test_batch_year_must_look_like_2026_27(): void
    {
        $super = $this->createCompany();
        Sanctum::actingAs($super);

        $this->postJson('/api/academic-years', ['name' => '2026-28'])->assertStatus(422);
        $this->postJson('/api/academic-years', ['name' => '26-27'])->assertStatus(422);

        $this->postJson('/api/academic-years', ['name' => '2025-26'])
            ->assertCreated()
            ->assertJsonPath('data.name', '2025-26');

        $this->postJson('/api/academic-years', ['name' => '2025-26'])->assertStatus(422);
    }

    public function test_employee_cannot_add_a_batch_year(): void
    {
        $this->createCompany();
        $branch = $this->createBranch();
        $employee = $this->createUser([
            'email' => 'emp.year@acme.test', 'role' => 'employee', 'branch_id' => $branch->id,
            'password' => 'password', 'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($employee);

        $this->postJson('/api/academic-years', ['name' => '2026-27'])->assertForbidden();
        $this->getJson('/api/academic-years')->assertOk();
    }
}
