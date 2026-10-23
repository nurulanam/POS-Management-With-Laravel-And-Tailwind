<?php

namespace Tests\Feature\Management;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

class BranchManagementTest extends ManagementTestCase
{
    public function test_admin_can_create_a_branch_and_save_its_settings(): void
    {
        $this->signIn();
        $this->get('/')->assertOk()->assertSee('Your first branch starts here');
        $this->get('/branches/create')->assertOk();
        $this->post('/branches', $this->branchData())->assertRedirect('/branches');
        $branch = Branch::firstOrFail();
        $this->get('/branches')->assertOk()->assertSee($branch->name);
        $this->get(route('branches.edit', $branch))->assertOk();
        $this->put(route('branches.update', $branch), [...$this->branchData(), 'tax_rate' => '12.25', 'currency' => 'USD'])
            ->assertRedirect(route('branches.edit', $branch));
        $this->assertSame('12.25', $branch->fresh()->tax_rate);
        $this->assertSame('USD', $branch->fresh()->currency);
    }

    public function test_non_admins_cannot_read_settings_or_create_or_update_branches(): void
    {
        $branch = $this->branch();
        foreach ([UserRole::Manager, UserRole::Cashier] as $role) {
            $user = $this->signIn($role);
            $user->branches()->attach($branch);
            $this->get('/branches')->assertForbidden();
            $this->get('/branches/create')->assertForbidden();
            $this->get(route('branches.edit', $branch))->assertForbidden();
            $this->post('/branches', $this->branchData('NEW'))->assertForbidden();
            $this->put(route('branches.update', $branch), $this->branchData())->assertForbidden();
        }
        $this->assertDatabaseCount('branches', 1);
    }

    public function test_validation_rejects_invalid_settings_and_duplicate_branch_codes(): void
    {
        $this->signIn();
        $this->branch();
        $this->post('/branches', $this->branchData())->assertSessionHasErrors('code');
        $this->post('/branches', [...$this->branchData('NEW'), 'tax_rate' => 101, 'timezone' => 'invalid', 'currency' => 'BAD'])
            ->assertSessionHasErrors(['tax_rate', 'timezone', 'currency']);
        $this->post('/branches', [...$this->branchData('NEW'), 'tax_rate' => '1.999'])->assertSessionHasErrors('tax_rate');
    }

    public function test_settings_are_isolated_between_branches(): void
    {
        $this->signIn();
        $first = $this->branch();
        $second = $this->branch('SECOND');
        $this->put(route('branches.update', $first), [...$this->branchData(), 'currency' => 'USD', 'tax_rate' => 12])->assertSessionHasNoErrors();
        $this->assertSame('BDT', $second->fresh()->currency);
        $this->assertSame('7.50', $second->fresh()->tax_rate);
    }

    public function test_last_active_branch_cannot_be_deactivated_and_records_are_not_deleted(): void
    {
        $this->signIn();
        $first = $this->branch();
        $this->put(route('branches.update', $first), [...$this->branchData(), 'is_active' => false])->assertSessionHasErrors('is_active');
        $second = $this->branch('SECOND');
        $this->put(route('branches.update', $first), [...$this->branchData(), 'is_active' => false])->assertRedirect(route('branches.edit', $first));
        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertDatabaseCount('branches', 2);
    }

    public function test_manager_can_switch_only_between_assigned_active_branches(): void
    {
        $user = $this->signIn(UserRole::Manager);
        $first = $this->branch();
        $second = $this->branch('SECOND');
        $other = $this->branch('PRIVATE');
        $user->branches()->attach([$first->id, $second->id]);
        $this->get('/')->assertOk()->assertDontSee($other->name);
        $this->post('/branch/switch', ['branch_id' => $second->id])->assertRedirect('/')->assertSessionHas('branch_id', $second->id);
        $this->post('/branch/switch', ['branch_id' => $other->id])->assertNotFound();
        $second->update(['is_active' => false]);
        $this->post('/branch/switch', ['branch_id' => $second->id])->assertNotFound();
        $this->get('/')->assertSessionHas('branch_id', $first->id);
    }

    public function test_tampered_branch_session_does_not_expose_another_branch_or_team(): void
    {
        $manager = $this->signIn(UserRole::Manager);
        $own = $this->branch('OWN');
        $other = $this->branch('PRIVATE');
        $manager->branches()->attach($own);
        $local = User::factory()->create(['name' => 'Local Colleague']);
        $remote = User::factory()->create(['name' => 'Secret Remote Person']);
        $local->branches()->attach($own);
        $remote->branches()->attach($other);
        $this->withSession(['branch_id' => $other->id])->get('/team')->assertOk()
            ->assertSee('Local Colleague')->assertDontSee('Secret Remote Person')->assertDontSee($other->name)
            ->assertSessionHas('branch_id', $own->id);
        $manager->branches()->detach();
        $this->get('/team')->assertForbidden();
        $this->get('/')->assertOk()->assertSee('No active branch assigned')->assertDontSee($own->name);
    }

    public function test_cashier_cannot_access_team_directory_and_unassigned_users_cannot_access_pos_preview(): void
    {
        $this->signIn(UserRole::Cashier);
        $this->get('/team')->assertForbidden();
        $this->get('/pos-preview')->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee('href="'.route('users.index').'"', false)->assertDontSee('href="'.route('branches.index').'"', false);
    }

    public function test_admin_can_switch_to_any_active_branch_and_preview_is_explicitly_non_transactional(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $this->post('/branch/switch', ['branch_id' => $branch->id])->assertSessionHas('branch_id', $branch->id);
        $this->get('/pos-preview')->assertOk()->assertSee('Design preview only');
    }

    public function test_branch_text_is_escaped_when_rendered(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $branch->update(['name' => '<script>alert(1)</script>']);
        $this->get('/branches')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
