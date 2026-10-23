<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Support\Str;
use Tests\Feature\Management\ManagementTestCase;

abstract class OperationsTestCase extends ManagementTestCase
{
    protected function workspace(UserRole $role = UserRole::Admin): Branch
    {
        $user = $this->signIn($role);
        $branch = $this->branch();
        if (! $user->isAdmin()) {
            $user->branches()->attach($branch);
        }
        $this->withSession(['branch_id' => $branch->id]);

        return $branch;
    }

    protected function product(Branch $branch, array $overrides = []): Product
    {
        return $branch->products()->create(array_replace([
            'name' => 'Premium Tea', 'sku' => 'TEA-'.Str::random(6), 'barcode' => null,
            'price_minor' => 1250, 'stock' => 10, 'reorder_level' => 3, 'is_active' => true,
        ], $overrides));
    }

    protected function payment(Branch $branch, Product $product, array $overrides = []): array
    {
        return array_replace([
            'branch_id' => $branch->id, 'currency' => $branch->currency,
            'idempotency_key' => (string) Str::uuid(), 'expected_tax_basis_points' => 750,
            'tendered' => '100.00', 'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'expected_price_minor' => $product->price_minor],
            ],
        ], $overrides);
    }
}
