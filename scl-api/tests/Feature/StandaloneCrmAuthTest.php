<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StandaloneCrmAuthTest extends TestCase
{
    public function test_public_registration_is_removed(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New Org',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'New Co',
        ])->assertNotFound();
    }

    public function test_sso_login_is_removed(): void
    {
        $this->postJson('/api/sso/login', ['token' => 'anything'])->assertNotFound();
    }

    public function test_subscription_endpoints_are_removed(): void
    {
        $user = $this->createCompany();

        Sanctum::actingAs($user);

        $this->getJson('/api/subscription')
            ->assertNotFound();
    }

    public function test_superadmin_can_login_without_a_subscription(): void
    {
        $this->createCompany();

        $this->postJson('/api/auth/login', [
            'email' => 'owner@acme.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.email', 'owner@acme.test')
            ->assertJsonPath('user.role', 'superadmin')
            ->assertJsonMissingPath('user.subscription');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->createCompany([], ['is_active' => false, 'email' => 'off@acme.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'off@acme.test',
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');
    }

    public function test_inactive_company_cannot_login(): void
    {
        $this->createCompany(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'owner@acme.test',
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This CRM is currently inactive.');
    }
}
