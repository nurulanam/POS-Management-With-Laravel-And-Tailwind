<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, InventoryMovement, User};
use App\Services\Purchasing\Purchasing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_transfer_upgrade_preserves_purchasing_and_existing_stock_history(): void
    {
        $branch = Branch::create(['name' => 'Existing', 'code' => 'EXISTING', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Tea', 'sku' => 'TEA', 'price_minor' => 1000, 'stock' => 10, 'is_active' => true]);
        $supplier = $branch->suppliers()->create(['name' => 'Supplier', 'code' => 'SUP', 'is_active' => true]);
        $service = app(Purchasing::class);
        $order = $service->create($branch, $actor, [
            'supplier_id' => $supplier->id, 'currency' => 'BDT', 'expected_on' => null, 'notes' => 'Original order', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '5.00']],
        ]);
        $receipt = $service->receive($branch, $order, $actor, [
            'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'delivery_reference' => 'ORIGINAL', 'notes' => 'Original receiving',
            'items' => [['purchase_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 2]],
        ]);
        $tables = ['branches', 'users', 'products', 'suppliers', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'inventory_movements'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
        $before = $snapshot();
        $migration = require database_path('migrations/2026_10_27_000007_add_stock_transfers.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('stock_transfers'));
        $migration->up();
        $this->assertEquals($before, $snapshot());
        $this->assertSame(12, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', ['purchase_receipt_id' => $receipt->id, 'delta' => 2, 'balance' => 12]);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('transfer_receipts', 0);
    }
}
