<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, User};
use App\Services\Pos\{Checkout, RefundSale};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_existing_discounted_sales_refunds_and_inventory(): void
    {
        $branch = Branch::create(['name' => 'Existing branch', 'code' => 'LEGACY', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '7.50', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Legacy item', 'sku' => 'LEGACY', 'price_minor' => 1000, 'stock' => 10, 'is_active' => true]);
        $sale = app(Checkout::class)->complete($branch, $user, [
            'currency' => 'BDT', 'expected_tax_basis_points' => 750, 'tendered' => '20.00',
            'discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Promotion',
            'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_price_minor' => 1000]],
        ]);
        $refund = app(RefundSale::class)->record($branch, $sale, $user, [
            'idempotency_key' => (string) Str::uuid(), 'reason' => 'Item returned',
            'items' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'expected_returned' => 0, 'restock' => true]],
        ]);
        $saleBefore = $sale->fresh()->getAttributes();
        $refundBefore = $refund->fresh()->getAttributes();
        $itemsBefore = $sale->items()->get()->toArray();
        $refundItemsBefore = $refund->items()->get()->toArray();
        // Reconstruct the previous schema with real transactional data, then apply the new migration.
        $migration = require database_path('migrations/2026_10_27_000005_add_cash_drawer_sessions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('cash_sessions'));
        $this->assertFalse(Schema::hasColumn('sales', 'cash_session_id'));
        $migration->up();
        $this->assertEquals($saleBefore, $sale->fresh()->getAttributes());
        $this->assertEquals($refundBefore, $refund->fresh()->getAttributes());
        $this->assertSame($itemsBefore, $sale->items()->get()->toArray());
        $this->assertSame($refundItemsBefore, $refund->items()->get()->toArray());
        $this->assertFalse($branch->fresh()->cash_tracking_enabled);
        $this->assertNull($sale->fresh()->cash_session_id);
        $this->assertNull($refund->fresh()->cash_session_id);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('inventory_movements', 2);
    }
}
