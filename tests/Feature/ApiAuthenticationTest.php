<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_user_endpoint_accepts_a_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('upgrade-test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');
    }

    public function test_user_endpoint_rejects_an_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_user_endpoint_rejects_an_invalid_token(): void
    {
        $this->withToken('invalid-token')->getJson('/api/user')->assertUnauthorized();
    }
}
