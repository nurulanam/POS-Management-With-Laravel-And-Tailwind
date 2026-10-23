<?php

namespace Tests\Feature\Management;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthenticationTest extends ManagementTestCase
{
    public function test_guests_are_redirected_to_login_and_registration_is_not_public(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
        $this->get('/branches')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in to your workspace');
        $this->post('/register', [])->assertNotFound();
    }

    public function test_active_staff_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'CorrectPassword123']);
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'CorrectPassword123'])
            ->assertRedirect('/')->assertSessionHas('user_version', $user->session_version);
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_and_inactive_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'CorrectPassword123', 'is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'CorrectPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->is_active = true;
        $user->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'CorrectPassword123']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'CorrectPassword123'])
            ->assertSessionHasErrors('email')
            ->assertSessionHas('errors', fn ($errors) => str_contains($errors->first('email'), 'Too many attempts.'));
        $this->assertGuest();
    }

    public function test_deactivation_revokes_an_existing_session(): void
    {
        $user = $this->signIn(UserRole::Cashier);
        $user->is_active = false;
        $user->save();
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_revoked_session_stays_invalid_after_reactivation(): void
    {
        $user = $this->signIn();
        $user->session_version++;
        $user->save();
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_temporary_password_must_be_changed_before_using_workspace(): void
    {
        $user = User::factory()->create(['must_change_password' => true, 'password' => 'TemporaryPass123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'TemporaryPass123'])->assertRedirect('/account/password');
        $this->get('/')->assertRedirect('/account/password');
        $this->get('/account/password')->assertOk();
        $this->put('/account/password', [
            'current_password' => 'TemporaryPass123', 'password' => 'MyNewPassword123',
            'password_confirmation' => 'MyNewPassword123',
        ])->assertRedirect('/');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('MyNewPassword123', $user->fresh()->password));
        $this->get('/')->assertOk();
    }

    public function test_password_change_requires_current_password_and_revokes_tokens(): void
    {
        $user = $this->signIn();
        $user->createToken('test');
        $data = ['current_password' => 'wrong', 'password' => 'MyNewPassword123', 'password_confirmation' => 'MyNewPassword123'];
        $this->put('/account/password', $data)->assertSessionHasErrors('current_password');
        $this->put('/account/password', [...$data, 'current_password' => 'password'])->assertRedirect('/');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(2, $user->fresh()->session_version);
        $this->get('/')->assertOk();
    }

    public function test_inactive_or_temporary_password_users_cannot_use_api_tokens(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->withToken($user->createToken('inactive')->plainTextToken)->getJson('/api/user')->assertForbidden();
    }

    public function test_temporary_password_users_cannot_bypass_password_change_with_an_api_token(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->withToken($user->createToken('temporary')->plainTextToken)->getJson('/api/user')->assertForbidden();
    }

    public function test_create_admin_command_uses_hidden_password_input_and_does_not_create_a_branch(): void
    {
        $this->artisan('pos:create-admin')
            ->expectsQuestion('Administrator name', 'Business Owner')
            ->expectsQuestion('Email address', 'OWNER@example.com')
            ->expectsQuestion('Password (at least 12 characters, with letters and numbers)', 'PrivatePassword123')
            ->expectsQuestion('Confirm password', 'PrivatePassword123')
            ->assertSuccessful();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($owner->isAdmin());
        $this->assertTrue(Hash::check('PrivatePassword123', $owner->password));
        $this->assertDatabaseCount('branches', 0);
    }
}
