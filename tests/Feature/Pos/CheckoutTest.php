<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Str;

class CheckoutTest extends OperationsTestCase
{
    public function test_cash_sale_calculates_server_totals_and_records_stock_and_receipt_atomically(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $product = $this->product($branch);
        $customer = $branch->customers()->create(['name' => 'Amina', 'is_active' => true]);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['total_minor' => 1, 'tax_minor' => 0, 'customer_id' => $customer->id]))->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->assertSame(2500, $sale->subtotal_minor);
        $this->assertSame(188, $sale->tax_minor);
        $this->assertSame(2688, $sale->total_minor);
        $this->assertSame(7312, $sale->change_minor);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame('Amina', $sale->customer_snapshot['name']);
        $this->assertSame(2500, $sale->items()->first()->line_total_minor);
        $this->assertDatabaseHas('inventory_movements', ['sale_id' => $sale->id, 'delta' => -2, 'balance' => 8]);
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('BDT 26.88')->assertSee('Paid in cash');
    }

    public function test_repeated_identical_checkout_is_idempotent_but_changed_payload_is_rejected(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $data = $this->payment($branch, $product);
        $first = $this->post('/pos/checkout', $data)->assertRedirect();
        $this->post('/pos/checkout', $data)->assertRedirect($first->headers->get('Location'));
        $this->post('/pos/checkout', [...$data, 'tendered' => '101.00'])->assertStatus(409);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_insufficient_stock_in_any_line_rolls_back_the_entire_sale(): void
    {
        $branch = $this->workspace();
        $first = $this->product($branch);
        $second = $this->product($branch, ['stock' => 1]);
        $data = $this->payment($branch, $first);
        $data['items'][] = ['product_id' => $second->id, 'quantity' => 2, 'expected_price_minor' => $second->price_minor];
        $this->post('/pos/checkout', $data)->assertSessionHasErrors('items');
        $this->assertSame(10, $first->fresh()->stock);
        $this->assertSame(1, $second->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_stale_price_and_tax_quotes_require_review_instead_of_silently_charging_more(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $data = $this->payment($branch, $product);
        $product->update(['price_minor' => 1300]);
        $this->post('/pos/checkout', $data)->assertSessionHasErrors('items');
        $data['items'][0]['expected_price_minor'] = 1300;
        $branch->update(['tax_rate' => '10.00']);
        $this->post('/pos/checkout', $data)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_invalid_cash_and_duplicate_or_non_positive_quantities_are_rejected(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        foreach (['0.01', '-1', '1e5', '10.001'] as $cash) {
            $this->post('/pos/checkout', $this->payment($branch, $product, ['tendered' => $cash]))->assertSessionHasErrors('tendered');
        }
        $data = $this->payment($branch, $product);
        $data['items'][] = $data['items'][0];
        $this->post('/pos/checkout', $data)->assertSessionHasErrors('items.0.product_id');
        foreach ([0, -1, 1.5, 10001] as $quantity) {
            $data['items'] = [['product_id' => $product->id, 'quantity' => $quantity, 'expected_price_minor' => 1250]];
            $this->post('/pos/checkout', $data)->assertSessionHasErrors('items.0.quantity');
        }
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_foreign_or_archived_products_and_customers_cannot_be_sold(): void
    {
        $branch = $this->workspace();
        $other = $this->branch('OTHER');
        $local = $this->product($branch);
        $foreign = $this->product($other);
        $customer = $other->customers()->create(['name' => 'Private customer']);
        $this->post('/pos/checkout', $this->payment($branch, $foreign))->assertSessionHasErrors('items');
        $this->post('/pos/checkout', $this->payment($branch, $local, ['customer_id' => $customer->id]))->assertSessionHasErrors('customer_id');
        $local->update(['is_active' => false]);
        $this->post('/pos/checkout', $this->payment($branch, $local))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_checkout_rejects_stale_branch_and_currency_fields(): void
    {
        $branch = $this->workspace();
        $other = $this->branch('OTHER');
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['branch_id' => $other->id]))->assertSessionHasErrors('branch_id');
        $this->post('/pos/checkout', $this->payment($branch, $product, ['currency' => 'USD']))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_receipt_snapshots_survive_later_edits_and_product_archive(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $customer = $branch->customers()->create(['name' => 'Original Customer']);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['customer_id' => $customer->id]))->assertRedirect();
        $sale = Sale::firstOrFail();
        $branch->update(['name' => 'Renamed branch', 'tax_rate' => '99.00', 'receipt_footer' => 'Changed footer']);
        $product->update(['name' => 'Renamed product', 'price_minor' => 9999, 'is_active' => false]);
        $customer->update(['name' => 'Renamed customer', 'is_active' => false]);
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Premium Tea')->assertSee('Original Customer')
            ->assertSee('BDT 26.88')->assertSee('Thank you for visiting.')->assertDontSee('Changed footer')->assertDontSee('Renamed product');
    }

    public function test_cashiers_see_only_their_own_sales_and_managers_see_the_branch(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $cashier = auth()->user();
        $product = $this->product($branch);
        $data = $this->payment($branch, $product);
        $this->post('/pos/checkout', $data)->assertRedirect();
        $sale = Sale::firstOrFail();
        $other = $this->signIn(UserRole::Cashier);
        $other->branches()->attach($branch);
        $this->get('/sales')->assertOk()->assertDontSee($sale->reference);
        $this->get(route('sales.show', $sale))->assertNotFound();
        $this->post('/pos/checkout', $data)->assertForbidden();
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach($branch);
        $this->get(route('sales.show', $sale))->assertOk();
        $foreign = $this->branch('PRIVATE');
        $this->signIn();
        $this->withSession(['branch_id' => $foreign->id])->get(route('sales.show', $sale))->assertNotFound();
    }

    public function test_jpy_uses_whole_minor_units_and_rounds_aggregate_tax(): void
    {
        $branch = $this->workspace();
        $branch->update(['currency' => 'JPY']);
        $product = $this->product($branch, ['price_minor' => 101]);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['tendered' => '500']))->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->assertSame(202, $sale->subtotal_minor);
        $this->assertSame(15, $sale->tax_minor);
        $this->assertSame(217, $sale->total_minor);
        $this->get(route('sales.show', $sale))->assertSee('JPY 217');
    }

    public function test_a_second_sale_cannot_sell_stock_already_consumed_by_the_first(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch, ['stock' => 2]);
        $this->post('/pos/checkout', $this->payment($branch, $product))->assertRedirect();
        $this->post('/pos/checkout', $this->payment($branch, $product))->assertSessionHasErrors('items');
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_sales_date_filters_use_branch_timezone(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(21, 0)); // Oct 25 in Dhaka.
        $this->post('/pos/checkout', $this->payment($branch, $product))->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->get('/sales?from=2026-10-25&to=2026-10-25')->assertOk()->assertSee($sale->reference);
        $this->get('/sales?from=2026-10-24&to=2026-10-24')->assertOk()->assertDontSee($sale->reference);
        $this->get('/sales?from=2026-10-25&to=2026-10-24')->assertSessionHasErrors('to');
    }
}
