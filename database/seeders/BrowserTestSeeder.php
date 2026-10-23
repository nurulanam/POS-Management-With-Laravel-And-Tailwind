<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || ! env('E2E_ADMIN_PASSWORD')) {
            throw new LogicException('Browser fixtures require APP_ENV=testing and E2E_ADMIN_PASSWORD. Never seed these into a real database.');
        }
        $first = Branch::create(['name' => 'Dhanmondi Store', 'code' => 'DHN', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '7.50', 'is_active' => true, 'address' => '27 Dhanmondi, Dhaka']);
        $second = Branch::create(['name' => 'Uttara Store', 'code' => 'UTT', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '7.50', 'is_active' => true]);
        foreach ([UserRole::Admin, UserRole::Manager, UserRole::Cashier] as $role) {
            $user = User::factory()->create([
                'name' => $role->label().' Example', 'email' => $role->value.'@example.test',
                'role' => $role, 'password' => env('E2E_ADMIN_PASSWORD'),
            ]);
            if ($role !== UserRole::Admin) {
                $user->branches()->attach($role === UserRole::Manager ? $first : $second);
            }
        }
    }
}
