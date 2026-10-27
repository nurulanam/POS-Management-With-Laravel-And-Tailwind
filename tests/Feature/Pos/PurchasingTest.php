<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, PurchaseOrder, PurchaseReceipt, Supplier};
use App\Services\Purchasing\Purchasing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchasingTest extends OperationsTestCase
{
    private function supplier(Branch $branch): Supplier
    {
        return $branch->suppliers()->create(['name' => 'Tea Supply', 'code' => 'SUP-'.Str::random(5), 'is_active' => true]);
    }

    private function payload($branch, $supplier, $product): array
    {
        return ['branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'currency' => $branch->currency,
            'idempotency_key' => (string) Str::uuid(), 'confirmed' => 1, 'supplier_reference' => 'QUOTE-1',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '6.25']]];
    }

    private function ordered(): array
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $supplier = $this->supplier($branch);
        $this->post('/purchases', $this->payload($branch, $supplier, $product))->assertRedirect();
        return [$branch, $product, $supplier, PurchaseOrder::firstOrFail()];
    }

    private function delivery($branch, $order, int $quantity = 2): array
    {
        return ['branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version,
            'delivery_reference' => 'DELIVERY-1', 'notes' => 'Counted and inspected', 'confirmed' => 1,
            'items' => [['purchase_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => $quantity]]];
    }

    private function closure($branch, $order): array
    {
        return ['branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version,
            'reason' => 'Remaining units unavailable', 'confirmed' => 1];
    }

    public function test_supplier_create_edit_archive_and_stale_edit_protection(): void
    {
        $branch = $this->workspace(UserRole::Manager);
        $data = ['branch_id' => $branch->id, 'code' => 'sup-1', 'name' => 'First supplier', 'is_active' => 1];
        $this->post('/suppliers', $data)->assertRedirect();
        $supplier = Supplier::firstOrFail();
        $this->assertSame('SUP-1', $supplier->code);
        $this->get('/suppliers?q=SUP-1')->assertOk()->assertSee('First supplier');
        $this->get(route('suppliers.edit', $supplier))->assertOk();
        $this->put(route('suppliers.update', $supplier), [...$data, 'expected_version' => 1, 'is_active' => 0])->assertRedirect();
        $this->assertFalse($supplier->fresh()->is_active);
        $this->put(route('suppliers.update', $supplier), [...$data, 'expected_version' => 1])->assertSessionHasErrors('expected_version');
        $this->post('/suppliers', $data)->assertSessionHasErrors('code');
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_order_records_snapshots_and_costs_without_stock_or_cash_changes(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $this->assertSame(3125, $order->total_minor);
        $this->assertSame(625, $order->items()->first()->unit_cost_minor);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('cash_entries', 0);
        $supplier->update(['name' => 'Renamed supplier', 'is_active' => false]);
        $product->update(['name' => 'New product name', 'price_minor' => 9999, 'is_active' => false]);
        $branch->update(['name' => 'New branch name']);
        $this->get(route('purchases.show', $order))->assertOk()->assertSee('Tea Supply')->assertSee('Premium Tea')->assertSee('BDT 31.25');
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertRedirect();
        $this->assertSame(12, $product->fresh()->stock);
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(9999, $product->fresh()->price_minor);
        $receipt = PurchaseReceipt::firstOrFail();
        $this->get(route('purchase-receipts.show', $receipt))->assertOk()->assertSee('Tea Supply')->assertSee('Premium Tea')->assertSee('BDT 12.50');
    }

    public function test_partial_then_complete_receiving_reconciles_stock_value_and_ledger(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $this->get(route('purchases.receive', $order))->assertOk();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertRedirect();
        $this->assertSame('partial', $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->version);
        $this->assertSame(2, $order->items()->first()->received_quantity);
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order->fresh(), 3))->assertRedirect();
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(15, $product->fresh()->stock);
        $this->assertSame(3125, (int) $order->receipts()->sum('total_minor'));
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('inventory_movements', ['delta' => 3, 'balance' => 15, 'purchase_receipt_id' => PurchaseReceipt::orderByDesc('id')->first()->id]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('Goods receipt');
        $this->get(route('purchases.show', $order))->assertOk()->assertSee('Fully received')->assertDontSee('Receive goods →');
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order->fresh(), 1))->assertSessionHasErrors('expected_version');
    }

    public function test_duplicate_create_and_receive_retries_cannot_duplicate_orders_or_stock(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $supplier = $this->supplier($branch);
        $data = $this->payload($branch, $supplier, $product);
        $this->post('/purchases', $data)->assertRedirect();
        $order = PurchaseOrder::firstOrFail();
        $this->post('/purchases', $data)->assertRedirect(route('purchases.show', $order));
        $this->post('/purchases', [...$data, 'supplier_reference' => 'OTHER'])->assertStatus(409);
        $receive = $this->delivery($branch, $order, 5);
        $this->post(route('purchases.receive.store', $order), $receive)->assertRedirect();
        $receipt = PurchaseReceipt::firstOrFail();
        $this->post(route('purchases.receive.store', $order), $receive)->assertRedirect(route('purchase-receipts.show', $receipt));
        $this->post(route('purchases.receive.store', $order), [...$receive, 'notes' => 'Changed notes'])->assertStatus(409);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertSame(15, $product->fresh()->stock);
    }

    public function test_stale_and_over_receipts_are_rejected_without_extra_stock(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order, 6))->assertSessionHasErrors('items');
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order, 0))->assertSessionHasErrors('items');
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertRedirect();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertSessionHasErrors('expected_version');
        $this->assertSame(12, $product->fresh()->stock);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }

    public function test_close_short_preserves_receipts_and_rejects_further_receiving(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $receiptData = $this->delivery($branch, $order);
        $staleClose = $this->closure($branch, $order);
        $this->post(route('purchases.receive.store', $order), $receiptData)->assertRedirect();
        $this->post(route('purchases.close', $order), $staleClose)->assertSessionHasErrors('expected_version');
        $close = $this->closure($branch, $order->fresh());
        $this->post(route('purchases.close', $order), $close)->assertRedirect();
        $this->post(route('purchases.close', $order), $close)->assertRedirect();
        $this->post(route('purchases.close', $order), [...$close, 'reason' => 'Different reason'])->assertStatus(409);
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame(12, $product->fresh()->stock);
        $this->assertSame(2, $order->items()->first()->received_quantity);
        $this->assertSame(3125, $order->fresh()->total_minor);
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order->fresh()))->assertSessionHasErrors('expected_version');
        $this->post(route('purchases.receive.store', $order), $receiptData)->assertRedirect(); // Exact old retry still resolves.
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->get(route('purchases.show', $order))->assertOk()->assertSee('Closed short');
    }

    public function test_foreign_supplier_product_order_and_receipt_are_not_accessible(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $other = $this->branch('OTHER');
        $foreignSupplier = $this->supplier($other);
        $foreignProduct = $this->product($other);
        $this->post('/purchases', $this->payload($branch, $foreignSupplier, $product))->assertSessionHasErrors('supplier_id');
        $this->post('/purchases', $this->payload($branch, $supplier, $foreignProduct))->assertSessionHasErrors('items');
        $this->get(route('suppliers.edit', $foreignSupplier))->assertNotFound();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertRedirect();
        $receipt = PurchaseReceipt::firstOrFail();
        $this->withSession(['branch_id' => $other->id])->get(route('purchases.show', $order))->assertNotFound();
        $this->get(route('purchase-receipts.show', $receipt))->assertNotFound();
        $this->get('/purchases')->assertOk()->assertDontSee($order->reference);
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertSessionHasErrors('branch_id');
        $this->post(route('purchases.receive.store', $order), $this->delivery($other, $order))->assertNotFound();
    }

    public function test_cashiers_cannot_view_or_mutate_purchasing(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $user = $this->signIn(UserRole::Cashier);
        $user->branches()->attach($branch);
        foreach (['/suppliers', '/purchases', '/purchases/create', '/purchase-receipts', route('purchases.show', $order), route('purchases.receive', $order)] as $url) $this->get($url)->assertForbidden();
        $this->post('/purchases', $this->payload($branch, $supplier, $product))->assertForbidden();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertForbidden();
        $this->post(route('purchases.close', $order), $this->closure($branch, $order))->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee('href="'.route('purchases.index').'"', false);
    }

    public function test_archived_suppliers_and_products_cannot_be_used_in_new_orders(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $supplier->update(['is_active' => false]);
        $this->post('/purchases', $this->payload($branch, $supplier, $product))->assertSessionHasErrors('supplier_id');
        $supplier->update(['is_active' => true]);
        $product->update(['is_active' => false]);
        $this->post('/purchases', $this->payload($branch, $supplier, $product))->assertSessionHasErrors('items');
    }

    public function test_stock_limit_failure_rolls_back_all_lines_of_a_receipt(): void
    {
        [$branch, $product, $supplier, $original] = $this->ordered();
        $other = $this->product($branch, ['stock' => 1000000000]);
        $data = $this->payload($branch, $supplier, $product);
        $data['items'][] = ['product_id' => $other->id, 'quantity' => 1, 'unit_cost' => '1.00'];
        $this->post('/purchases', $data)->assertRedirect();
        $order = PurchaseOrder::orderByDesc('id')->firstOrFail();
        $receipt = $this->delivery($branch, $order);
        $receipt['items'] = $order->items->map(fn ($line) => ['purchase_order_item_id' => $line->id, 'quantity' => 1])->all();
        $this->post(route('purchases.receive.store', $order), $receipt)->assertSessionHasErrors('items');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, (int) $order->items()->sum('received_quantity'));
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_duplicate_and_foreign_lines_cannot_be_injected_into_receiving(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $this->post('/purchases', $this->payload($branch, $supplier, $product))->assertRedirect();
        $other = PurchaseOrder::orderByDesc('id')->firstOrFail();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $other))->assertSessionHasErrors('items');
        $data = $this->delivery($branch, $order);
        $data['items'][] = $data['items'][0];
        $this->post(route('purchases.receive.store', $order), $data)->assertSessionHasErrors('items.0.purchase_order_item_id');
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_invalid_costs_quantities_currency_and_confirmation_are_rejected(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $data = $this->payload($branch, $supplier, $product);
        foreach (['-1', '1.001', '10000000.01'] as $cost) {
            $data['items'][0]['unit_cost'] = $cost;
            $this->post('/purchases', $data)->assertSessionHasErrors('items');
        }
        $data = $this->payload($branch, $supplier, $product);
        $data['items'][0]['quantity'] = 10001;
        $this->post('/purchases', $data)->assertSessionHasErrors('items.0.quantity');
        $this->post('/purchases', [...$this->payload($branch, $supplier, $product), 'currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->post('/purchases', [...$this->payload($branch, $supplier, $product), 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->post(route('purchases.receive.store', $order), [...$this->delivery($branch, $order), 'confirmed' => false])->assertSessionHasErrors('confirmed');
    }

    public function test_zero_cost_and_jpy_orders_receive_whole_units_without_cash(): void
    {
        $branch = $this->workspace();
        $branch->forceFill(['currency' => 'JPY', 'cash_tracking_enabled' => true])->save();
        $supplier = $this->supplier($branch);
        $product = $this->product($branch);
        $data = $this->payload($branch, $supplier, $product);
        $this->post('/purchases', $data)->assertSessionHasErrors('items');
        $data['items'][0]['unit_cost'] = '0';
        $this->post('/purchases', $data)->assertRedirect();
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order, 5))->assertRedirect();
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(0, PurchaseReceipt::firstOrFail()->total_minor);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_largest_supported_order_value_is_exact_and_larger_orders_are_rejected(): void
    {
        [$branch, $product, $supplier, $original] = $this->ordered();
        $data = $this->payload($branch, $supplier, $product);
        $data['items'][0] = ['product_id' => $product->id, 'quantity' => 1001, 'unit_cost' => '10000000.00'];
        $this->post('/purchases', $data)->assertSessionHasErrors('items');
        $data['items'][0]['quantity'] = 1000;
        $this->post('/purchases', $data)->assertRedirect();
        $order = PurchaseOrder::orderByDesc('id')->firstOrFail();
        $this->assertSame(1000000000000, $order->total_minor);
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order, 1000))->assertRedirect();
        $this->assertSame(1000000000000, PurchaseReceipt::firstOrFail()->total_minor);
    }

    public function test_receipt_amounts_are_server_calculated_and_keys_are_actor_bound(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $data = [...$this->delivery($branch, $order), 'total_minor' => 1, 'user_id' => 99999];
        $this->post(route('purchases.receive.store', $order), $data)->assertRedirect();
        $this->assertSame(1250, PurchaseReceipt::firstOrFail()->total_minor);
        $this->assertSame(auth()->id(), PurchaseReceipt::firstOrFail()->user_id);
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach($branch);
        $this->post(route('purchases.receive.store', $order), $data)->assertForbidden();
        $this->assertSame(12, $product->fresh()->stock);
    }

    public function test_service_rechecks_role_after_actor_was_loaded(): void
    {
        [$branch, $product, $supplier, $order] = $this->ordered();
        $actor = auth()->user();
        $actor->branches()->attach($branch);
        DB::table('users')->where('id', $actor->id)->update(['role' => 'cashier']);
        try {
            app(Purchasing::class)->receive($branch, $order, $actor, $this->delivery($branch, $order));
            $this->fail('A demoted actor cannot receive goods.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $error) {
            $this->assertDatabaseCount('purchase_receipts', 0);
        }
    }

    public function test_order_and_receiving_dates_use_separate_branch_local_filters(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 23:30', 'Asia/Dhaka')->utc());
        [$branch, $product, $supplier, $order] = $this->ordered();
        $this->travel(2)->hours();
        $this->post(route('purchases.receive.store', $order), $this->delivery($branch, $order))->assertRedirect();
        $receipt = PurchaseReceipt::firstOrFail();
        $this->get('/purchases?from=2026-10-27&to=2026-10-27')->assertOk()->assertSee($order->reference);
        $this->get('/purchases?from=2026-10-28&to=2026-10-28')->assertOk()->assertDontSee($order->reference);
        $this->get('/purchase-receipts?from=2026-10-28&to=2026-10-28')->assertOk()->assertSee($receipt->reference);
        $this->get('/purchase-receipts?from=2026-10-27&to=2026-10-27')->assertOk()->assertDontSee($receipt->reference);
    }
}
