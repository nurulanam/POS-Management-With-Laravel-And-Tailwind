<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, CashEntry, InventoryMovement, User};
use App\Services\Pos\{CashDrawer, Checkout, RefundSale};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasingMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_sales_refunds_till_reconciliation_and_stock(): void
    {
        $branch = Branch::create(['name' => 'Existing store', 'code' => 'EXISTING', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '7.50', 'is_active' => true]);
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Tea', 'sku' => 'TEA', 'price_minor' => 1000, 'stock' => 10, 'is_active' => true]);
        $drawer = app(CashDrawer::class);
        $drawer->enable($branch, $actor);
        $till = $drawer->open($branch, $actor, ['opening' => '100.00', 'currency' => 'BDT', 'idempotency_key' => (string) Str::uuid()]);
        $sale = app(Checkout::class)->complete($branch, $actor, [
            'cash_session_id' => $till->id, 'currency' => 'BDT', 'expected_tax_basis_points' => 750, 'tendered' => '20.00',
            'discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Promotion', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_price_minor' => 1000]],
        ]);
        $refund = app(RefundSale::class)->record($branch, $sale, $actor, [
            'cash_session_id' => $till->id, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Returned item',
            'items' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'expected_returned' => 0, 'restock' => true]],
        ]);
        $drawer->close($branch, $till, $actor, ['idempotency_key' => (string) Str::uuid(), 'counted' => '109.67', 'expected_version' => 2, 'reason' => 'Physically counted']);
        $before = [$branch->fresh()->toArray(), $sale->fresh()->toArray(), $refund->fresh()->toArray(), $till->fresh()->toArray(), CashEntry::orderBy('id')->get()->toArray(), InventoryMovement::orderBy('id')->get()->toArray()];
        $migration = require database_path('migrations/2026_10_27_000006_add_suppliers_and_purchasing.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('purchase_orders'));
        $migration->up();
        $after = [$branch->fresh()->toArray(), $sale->fresh()->toArray(), $refund->fresh()->toArray(), $till->fresh()->toArray(), CashEntry::orderBy('id')->get()->toArray(), InventoryMovement::orderBy('id')->get()->toArray()];
        $this->assertEquals($before, $after);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertSame(0, $till->fresh()->variance_minor);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('suppliers', 0);
    }
}
