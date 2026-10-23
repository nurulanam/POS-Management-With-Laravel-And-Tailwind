<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Refund;
use App\Models\Sale;
use Illuminate\Support\Str;

class RefundTest extends OperationsTestCase
{
    private function sell(array $discount = []): array
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product, $discount))->assertRedirect();

        return [$branch, $product, Sale::firstOrFail()];
    }

    private function payload($branch, Sale $sale, int $quantity = 1, bool $restock = false, int $returned = 0): array
    {
        return [
            'branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Customer returned the item', 'confirmed' => '1',
            'items' => [['sale_item_id' => $sale->items()->first()->id, 'quantity' => $quantity, 'restock' => $restock, 'expected_returned' => $returned]],
        ];
    }

    public function test_partial_refund_restocks_only_checked_items_and_preserves_the_sale(): void
    {
        [$branch, $product, $sale] = $this->sell(['discount_type' => 'fixed', 'discount_value' => '5.00', 'discount_reason' => 'Promotion']);
        $before = $sale->getAttributes();
        $this->get(route('refunds.create', $sale))->assertOk()->assertSee('Select returned units');
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, restock: true))->assertRedirect();
        $refund = Refund::firstOrFail();
        $this->assertSame(1000, $refund->subtotal_minor);
        $this->assertSame(75, $refund->tax_minor);
        $this->assertSame(1075, $refund->total_minor);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', ['refund_id' => $refund->id, 'delta' => 1, 'balance' => 9]);
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->get(route('refunds.show', $refund))->assertOk()->assertSee('BDT 10.75')->assertSee('Premium Tea');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Partially returned')->assertSee('BDT 21.50');
    }

    public function test_split_refunds_preserve_original_pricing_and_never_over_refund(): void
    {
        [$branch, $product, $sale] = $this->sell(); // BDT 26.88: tax 188, evenly split here.
        $product->update(['price_minor' => 99999, 'is_active' => false]);
        $branch->update(['tax_rate' => '100.00', 'name' => 'Changed store']);
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale))->assertRedirect();
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, returned: 1))->assertRedirect();
        $this->assertSame($sale->total_minor, (int) $sale->refunds()->sum('total_minor'));
        $this->assertSame($sale->tax_minor, (int) $sale->refunds()->sum('tax_minor'));
        $this->assertSame(8, $product->fresh()->stock); // No restock selected.
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('All units returned');
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, returned: 2))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('refunds', 2);
    }

    public function test_full_return_of_discounted_multiline_sale_reconciles_every_cent(): void
    {
        $branch = $this->workspace();
        $first = $this->product($branch, ['price_minor' => 101]);
        $second = $this->product($branch, ['price_minor' => 102]);
        $data = $this->payment($branch, $first, ['discount_type' => 'fixed', 'discount_value' => '0.01', 'discount_reason' => 'Rounding promotion']);
        $data['items'][] = ['product_id' => $second->id, 'quantity' => 3, 'expected_price_minor' => 102];
        $this->post('/pos/checkout', $data)->assertRedirect();
        $sale = Sale::firstOrFail();
        foreach ($sale->items as $line) {
            for ($i = 0; $i < $line->quantity; $i++) {
                $payload = $this->payload($branch, $sale);
                $payload['items'] = [['sale_item_id' => $line->id, 'quantity' => 1, 'expected_returned' => $i, 'restock' => true]];
                $this->post(route('refunds.store', $sale), $payload)->assertRedirect();
            }
        }
        $this->assertSame($sale->total_minor, (int) $sale->refunds()->sum('total_minor'));
        $this->assertSame($sale->subtotal_minor - 1, (int) $sale->refunds()->sum('subtotal_minor'));
        $this->assertSame($sale->tax_minor, (int) $sale->refunds()->sum('tax_minor'));
        $this->assertSame(10, $first->fresh()->stock);
        $this->assertSame(10, $second->fresh()->stock);
    }

    public function test_duplicate_refund_does_not_pay_or_restock_twice_and_changed_payload_conflicts(): void
    {
        [$branch, $product, $sale] = $this->sell();
        $payload = $this->payload($branch, $sale, restock: true);
        $first = $this->post(route('refunds.store', $sale), $payload)->assertRedirect();
        $this->post(route('refunds.store', $sale), $payload)->assertRedirect($first->headers->get('Location'));
        $this->post(route('refunds.store', $sale), [...$payload, 'reason' => 'Changed reason'])->assertStatus(409);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_stale_return_form_is_rejected_without_extra_cash_or_stock(): void
    {
        [$branch, $product, $sale] = $this->sell();
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, restock: true))->assertRedirect();
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, restock: true))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_cashier_cannot_refund_but_can_read_refunds_on_their_own_sales(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $cashier = auth()->user();
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product))->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->get(route('refunds.create', $sale))->assertForbidden();
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale))->assertForbidden();
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach($branch);
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale))->assertRedirect();
        $refund = Refund::firstOrFail();
        $this->actingAs($cashier)->withSession(['user_version' => $cashier->session_version, 'password_hash_web' => $cashier->password]);
        $this->get(route('refunds.show', $refund))->assertOk();
        $this->get('/refunds')->assertSee($refund->reference);
        $other = $this->signIn(UserRole::Cashier);
        $other->branches()->attach($branch);
        $this->get(route('refunds.show', $refund))->assertNotFound();
        $this->get('/refunds')->assertDontSee($refund->reference);
    }

    public function test_foreign_sale_and_stale_branch_cannot_be_refunded(): void
    {
        [$branch, $product, $sale] = $this->sell();
        $other = $this->branch('OTHER');
        $data = $this->payload($branch, $sale);
        $this->withSession(['branch_id' => $other->id])->get(route('refunds.create', $sale))->assertNotFound();
        $this->post(route('refunds.store', $sale), $data)->assertSessionHasErrors('branch_id');
        $this->post(route('refunds.store', $sale), [...$data, 'branch_id' => $other->id])->assertNotFound();
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_tampered_amounts_are_ignored_and_invalid_return_requests_are_rejected(): void
    {
        [$branch, $product, $sale] = $this->sell();
        $data = $this->payload($branch, $sale);
        $this->post(route('refunds.store', $sale), [...$data, 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->post(route('refunds.store', $sale), [...$data, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, 0))->assertSessionHasErrors('items');
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, 3))->assertSessionHasErrors('items');
        $duplicate = $data;
        $duplicate['items'][] = $duplicate['items'][0];
        $this->post(route('refunds.store', $sale), $duplicate)->assertSessionHasErrors('items.0.sale_item_id');
        $this->post(route('refunds.store', $sale), [...$data, 'total_minor' => 999999999, 'tax_minor' => 999999])->assertRedirect();
        $this->assertSame(1344, Refund::firstOrFail()->total_minor);
    }

    public function test_restock_limit_failure_rolls_back_the_financial_record(): void
    {
        [$branch, $product, $sale] = $this->sell();
        $product->update(['stock' => 1000000000]);
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, restock: true))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('refund_items', 0);
        $this->assertSame(1000000000, $product->fresh()->stock);
    }

    public function test_zero_value_fully_discounted_returns_track_units_and_stock(): void
    {
        [$branch, $product, $sale] = $this->sell(['discount_type' => 'percent', 'discount_value' => '100', 'discount_reason' => 'Complimentary items', 'tendered' => '0']);
        $this->assertSame(0, $sale->total_minor);
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, 2, true))->assertRedirect();
        $this->assertSame(0, Refund::firstOrFail()->total_minor);
        $this->assertSame(10, $product->fresh()->stock);
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, returned: 2))->assertSessionHasErrors('items');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('All units returned');
    }

    public function test_non_cash_sales_cannot_be_refunded_as_cash(): void
    {
        [$branch, $product, $sale] = $this->sell();
        $sale->update(['payment_method' => 'card']);
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale))->assertSessionHasErrors('sale');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_net_reporting_uses_refund_processing_date_and_can_be_negative(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        [$branch, $product, $sale] = $this->sell();
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
        $this->post(route('refunds.store', $sale), $this->payload($branch, $sale, 2))->assertRedirect();
        $refund = Refund::firstOrFail();
        $this->get('/')->assertOk()->assertSee('BDT -26.88');
        $this->get('/sales?from=2026-10-20&to=2026-10-20')->assertOk()->assertViewHas('total', 2688)->assertViewHas('refunded', 2688);
        $this->get('/refunds?from=2026-10-21&to=2026-10-21')->assertOk()->assertSee($refund->reference)->assertViewHas('total', 2688);
        $this->get('/refunds?from=2026-10-20&to=2026-10-20')->assertDontSee($refund->reference)->assertViewHas('total', 0);
    }
}
