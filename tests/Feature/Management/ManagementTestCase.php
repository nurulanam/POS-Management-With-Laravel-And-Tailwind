<?php

namespace Tests\Feature\Management;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ManagementTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function signIn(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['user_version' => $user->session_version, 'password_hash_web' => $user->password]);

        return $user;
    }

    protected function branch(string $code = 'DHN-01'): Branch
    {
        return Branch::create($this->branchData($code));
    }

    protected function branchData(string $code = 'DHN-01'): array
    {
        return [
            'name' => 'Branch '.$code, 'code' => $code, 'is_active' => true,
            'email' => 'store@example.com', 'phone' => '+8801700000000',
            'address' => 'Dhaka', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'tax_rate' => '7.50', 'receipt_footer' => 'Thank you for visiting.',
        ];
    }

    protected function staffData(Branch $branch, array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Cashier', 'email' => 'staff@example.com',
            'role' => 'cashier', 'is_active' => true,
            'password' => 'TemporaryPass123', 'password_confirmation' => 'TemporaryPass123',
            'branch_ids' => [$branch->id],
        ], $overrides);
    }
}
