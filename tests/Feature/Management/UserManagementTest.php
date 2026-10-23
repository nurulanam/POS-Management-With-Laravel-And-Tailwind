<?php

namespace Tests\Feature\Management;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserManagementTest extends ManagementTestCase
{
    public function test_admin_can_render_staff_pages_and_create_staff_with_branch_assignments(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $this->get('/users')->assertOk()->assertSee('Team directory');
        $this->get('/users/create')->assertOk();
        $this->post('/users', $this->staffData($branch, ['session_version' => 999]))->assertRedirect('/users');
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $this->assertSame(UserRole::Cashier, $staff->role);
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check('TemporaryPass123', $staff->password));
        $this->assertNotSame(999, $staff->session_version);
        $this->assertTrue($staff->branches->contains($branch));
        $this->get(route('users.edit', $staff))->assertOk();
    }

    public function test_managers_and_cashiers_cannot_read_or_mutate_user_management(): void
    {
        $branch = $this->branch();
        $target = User::factory()->create();
        foreach ([UserRole::Manager, UserRole::Cashier] as $role) {
            $this->signIn($role);
            $this->get('/users')->assertForbidden();
            $this->get('/users/create')->assertForbidden();
            $this->get(route('users.edit', $target))->assertForbidden();
            $this->post('/users', $this->staffData($branch))->assertForbidden();
            $this->put(route('users.update', $target), $this->staffData($branch, ['role' => 'admin']))->assertForbidden();
        }
        $this->assertSame(UserRole::Cashier, $target->fresh()->role);
    }

    public function test_staff_validation_rejects_invalid_roles_duplicate_email_weak_password_and_missing_branches(): void
    {
        $admin = $this->signIn();
        $branch = $this->branch();
        $this->post('/users', $this->staffData($branch, ['role' => 'superuser']))->assertSessionHasErrors('role');
        $this->post('/users', $this->staffData($branch, ['email' => $admin->email]))->assertSessionHasErrors('email');
        $this->post('/users', $this->staffData($branch, ['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post('/users', $this->staffData($branch, ['branch_ids' => []]))->assertSessionHasErrors('branch_ids');
        $branch->update(['is_active' => false]);
        $this->post('/users', $this->staffData($branch))->assertSessionHasErrors('branch_ids.0');
    }

    public function test_admin_can_update_roles_branches_and_status_without_resetting_password(): void
    {
        $this->signIn();
        $first = $this->branch();
        $second = $this->branch('UTT-02');
        $staff = User::factory()->create();
        $staff->branches()->attach($first);
        $staff->createToken('existing');
        $hash = $staff->password;
        $this->put(route('users.update', $staff), $this->staffData($second, [
            'email' => $staff->email, 'role' => 'manager', 'is_active' => false,
            'password' => null, 'password_confirmation' => null,
        ]))->assertRedirect('/users');
        $staff->refresh();
        $this->assertSame(UserRole::Manager, $staff->role);
        $this->assertFalse($staff->is_active);
        $this->assertSame($hash, $staff->password);
        $this->assertSame([$second->id], $staff->branches()->pluck('branches.id')->all());
        $this->assertSame(0, $staff->tokens()->count());
        $this->assertSame(2, $staff->session_version);
    }

    public function test_admin_cannot_deactivate_or_demote_their_own_account(): void
    {
        $admin = $this->signIn();
        $branch = $this->branch();
        $this->put(route('users.update', $admin), $this->staffData($branch, ['email' => $admin->email, 'role' => 'cashier']))->assertSessionHasErrors('role');
        $this->put(route('users.update', $admin), $this->staffData($branch, ['email' => $admin->email, 'role' => 'admin', 'is_active' => false]))->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_create_another_admin_without_explicit_branch_assignments(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $this->post('/users', $this->staffData($branch, ['role' => 'admin', 'branch_ids' => []]))->assertRedirect('/users');
        $this->assertTrue(User::where('email', 'staff@example.com')->firstOrFail()->isAdmin());
    }

    public function test_staff_search_and_filters_are_applied(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $staff = User::factory()->create(['name' => 'Unique Staff', 'role' => UserRole::Manager]);
        $staff->branches()->attach($branch);
        User::factory()->create(['name' => 'Other Person']);
        $this->get('/users?'.http_build_query(['q' => 'Unique', 'role' => 'manager', 'branch' => $branch->id, 'status' => 'active']))
            ->assertOk()->assertSee('Unique Staff')->assertDontSee('Other Person');
    }

    public function test_password_reset_sets_first_login_requirement_and_preserves_admin_self_session(): void
    {
        $admin = $this->signIn();
        $branch = $this->branch();
        $this->put(route('users.update', $admin), $this->staffData($branch, ['email' => $admin->email, 'role' => 'admin']))->assertRedirect('/users');
        $this->get('/')->assertRedirect('/account/password');
        $this->get('/account/password')->assertOk();
    }
}
