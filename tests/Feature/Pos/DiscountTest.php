<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Sale;

class DiscountTest extends OperationsTestCase
{
    public function test_manager_fixed_discount_reduces_tax_base_and_is_snapshotted(): void
    {
        $branch = $this->workspace(UserRole::Manager);
        $product = $this->product($branch);
        $payload = $this->payment($branch, $product, ['discount_type' => 'fixed', 'discount_value' => '5.00', 'discount_reason' => 'Damaged packaging']);
        $this->post('/pos/checkout', $payload)->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->assertSame(2500, $sale->subtotal_minor);
        $this->assertSame(500, $sale->discount_minor);
        $this->assertSame(150, $sale->tax_minor);
        $this->assertSame(2150, $sale->total_minor);
        $this->assertSame(auth()->id(), $sale->discount_authorized_by);
        $this->assertSame('Damaged packaging', $sale->discount_reason);
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('BDT 21.50')->assertSee('Discount authorized by')->assertSee('Damaged packaging');
        $this->post('/pos/checkout', $payload)->assertRedirect(route('sales.show', $sale));
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(8, $product->fresh()->stock);
        $this->post('/pos/checkout', [...$payload, 'discount_reason' => 'Different reason'])->assertStatus(409);
    }

    public function test_percentage_discount_rounds_once_then_taxes_the_net_amount(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch, ['price_minor' => 333]);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['discount_type' => 'percent', 'discount_value' => '12.50', 'discount_reason' => 'Promotion']))->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->assertSame(83, $sale->discount_minor); // 666 * 12.5% = 83.25.
        $this->assertSame(44, $sale->tax_minor); // 583 * 7.5% = 43.725.
        $this->assertSame(627, $sale->total_minor);
    }

    public function test_cashiers_cannot_apply_discounts_even_by_forging_a_post(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $product = $this->product($branch);
        $this->get('/pos')->assertOk()->assertDontSee('name="discount_type"', false);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['discount_type' => 'fixed', 'discount_value' => '1.00', 'discount_reason' => 'Forged discount']))->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_invalid_discounts_and_missing_reasons_are_rejected(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        foreach ([['fixed', '25.01'], ['fixed', '-1'], ['fixed', '0'], ['percent', '100.01'], ['percent', '10.001']] as [$kind, $value]) {
            $this->post('/pos/checkout', $this->payment($branch, $product, ['discount_type' => $kind, 'discount_value' => $value, 'discount_reason' => 'Promotion']))->assertSessionHasErrors('discount_value');
        }
        $this->post('/pos/checkout', $this->payment($branch, $product, ['discount_type' => 'fixed', 'discount_value' => '1.00']))->assertSessionHasErrors('discount_reason');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_old_no_discount_checkout_hashes_remain_replayable(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $data = $this->payment($branch, $product);
        $this->post('/pos/checkout', $data)->assertRedirect();
        $sale = Sale::firstOrFail();
        $legacy = hash('sha256', json_encode([$data['items'], 0, 10000, 'BDT', 750], JSON_THROW_ON_ERROR));
        $this->assertSame($legacy, $sale->request_hash);
        $this->post('/pos/checkout', [...$data, 'discount_type' => 'none'])->assertRedirect(route('sales.show', $sale));
    }

    public function test_jpy_fixed_discount_rejects_fractional_yen(): void
    {
        $branch = $this->workspace();
        $branch->update(['currency' => 'JPY']);
        $product = $this->product($branch, ['price_minor' => 100]);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['tendered' => '500', 'discount_type' => 'fixed', 'discount_value' => '1.50', 'discount_reason' => 'Promotion']))->assertSessionHasErrors('discount_value');
    }
}
