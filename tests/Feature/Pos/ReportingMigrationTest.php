<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, User};
use App\Services\Pos\{Checkout, RefundSale};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_reporting_indexes_preserve_transactions_stock_and_original_indexes(): void
    {
        $branch = Branch::create(['name' => 'Existing', 'code' => 'EXISTING', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Tea', 'sku' => 'TEA', 'price_minor' => 100, 'stock' => 10, 'is_active' => true]);
        $sale = app(Checkout::class)->complete($branch, $actor, ['currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '2.00', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_price_minor' => 100]]]);
        app(RefundSale::class)->record($branch, $sale, $actor, ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Return before upgrade', 'items' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'expected_returned' => 0, 'restock' => true]]]);
        $tables = ['branches', 'users', 'products', 'sales', 'sale_items', 'refunds', 'refund_items', 'inventory_movements'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
        $before = $snapshot();
        $migration = require database_path('migrations/2026_10_28_000008_add_reporting_indexes.php');
        $migration->down();
        $this->assertFalse(Schema::hasIndex('sales', 'sales_branch_reporting_date'));
        $this->assertTrue(Schema::hasIndex('products', ['branch_id', 'sku'], 'unique'));
        $this->assertEquals($before, $snapshot());
        $migration->up();
        $this->assertTrue(Schema::hasIndex('sales', 'sales_branch_reporting_date'));
        $this->assertTrue(Schema::hasIndex('inventory_movements', 'movements_branch_reporting_date'));
        $this->assertTrue(Schema::hasIndex('products', 'products_branch_low_stock'));
        $this->assertEquals($before, $snapshot());
    }
}
