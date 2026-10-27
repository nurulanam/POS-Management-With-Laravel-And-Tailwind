<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{CashEntry, CashSession, Refund, Sale};
use Illuminate\Support\Str;

class CashDrawerTest extends OperationsTestCase
{
    private function key(): string { return (string) Str::uuid(); }

    private function setupTill(string $opening = '100.00', UserRole $role = UserRole::Admin): array
    {
        $branch = $this->workspace($role);
        $branch->forceFill(['cash_tracking_enabled' => true])->save();
        $this->post('/cash-sessions', $this->opening($branch, $opening))->assertRedirect();
        return [$branch, CashSession::firstOrFail()];
    }

    private function opening($branch, string $amount = '100.00'): array
    {
        return ['branch_id' => $branch->id, 'idempotency_key' => $this->key(), 'opening' => $amount, 'currency' => $branch->currency, 'confirmed' => '1'];
    }

    private function closing($branch, $session, string $amount = '100.00'): array
    {
        return ['branch_id' => $branch->id, 'idempotency_key' => $this->key(), 'counted' => $amount, 'reason' => 'Counted at end of shift', 'expected_version' => $session->version, 'confirmed' => '1'];
    }

    private function movement($branch, $session, string $kind = 'in', string $amount = '5.00'): array
    {
        return ['branch_id' => $branch->id, 'idempotency_key' => $this->key(), 'kind' => $kind, 'amount' => $amount, 'reason' => 'Physical cash movement', 'expected_version' => $session->version, 'confirmed' => '1'];
    }

    private function returning($branch, $session, $sale): array
    {
        return ['branch_id' => $branch->id, 'cash_session_id' => $session->id, 'idempotency_key' => $this->key(), 'reason' => 'Item returned', 'confirmed' => '1', 'items' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'expected_returned' => 0, 'restock' => true]]];
    }

    public function test_tracking_is_explicit_manager_controlled_and_enable_is_idempotent(): void
    {
        $branch = $this->workspace(UserRole::Manager);
        $this->get('/cash-sessions')->assertOk()->assertSee('Start tracking cash');
        $this->post('/cash-sessions', $this->opening($branch))->assertSessionHasErrors('opening');
        $this->post('/cash-sessions/enable', ['branch_id' => $branch->id])->assertSessionHasErrors('confirmed');
        $this->post('/cash-sessions/enable', ['branch_id' => $branch->id, 'confirmed' => 1])->assertRedirect();
        $before = $branch->fresh()->getAttributes();
        $this->post('/cash-sessions/enable', ['branch_id' => $branch->id, 'confirmed' => 1])->assertRedirect();
        $this->assertSame($before, $branch->fresh()->getAttributes());
        $this->assertSame(auth()->id(), $branch->fresh()->cash_tracking_enabled_by);
        $this->get('/cash-sessions')->assertOk()->assertSee('Open your till');
    }

    public function test_opening_replays_but_a_second_open_till_is_rejected(): void
    {
        [$branch, $session] = $this->setupTill();
        $data = $this->opening($branch);
        $data['idempotency_key'] = $session->opening_key;
        $this->post('/cash-sessions', $data)->assertRedirect(route('cash.show', $session));
        $this->post('/cash-sessions', [...$data, 'opening' => '101.00'])->assertStatus(409);
        $this->post('/cash-sessions', $this->opening($branch))->assertSessionHasErrors('opening');
        $this->assertDatabaseCount('cash_sessions', 1);
    }

    public function test_sales_refunds_movements_close_and_review_reconcile_exactly(): void
    {
        [$branch, $session] = $this->setupTill();
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['cash_session_id' => $session->id]))->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->assertSame(12688, $session->fresh()->expected_minor); // Retains 26.88, NOT tendered 100.00.
        $this->assertSame($session->id, $sale->cash_session_id);
        $this->post(route('refunds.store', $sale), $this->returning($branch, $session, $sale))->assertRedirect();
        $this->assertSame(11344, $session->fresh()->expected_minor);
        $this->post(route('cash.movement', $session), $this->movement($branch, $session->fresh()))->assertRedirect();
        $this->post(route('cash.movement', $session), $this->movement($branch, $session->fresh(), 'out', '2.00'))->assertRedirect();
        $session->refresh();
        $this->assertSame(11644, $session->expected_minor);
        $this->assertSame(4, $session->version);
        $this->get(route('cash.show', $session))->assertOk()->assertSee('BDT 116.44');
        $close = $this->closing($branch, $session, '116.40');
        $this->post(route('cash.close', $session), $close)->assertRedirect();
        $this->post(route('cash.close', $session), $close)->assertRedirect();
        $this->assertSame(-4, $session->fresh()->variance_minor);
        $this->assertNull($session->fresh()->active_owner_id);
        $this->get(route('cash.show', $session))->assertOk()->assertSee('Awaiting variance review')->assertSee('BDT -0.04');
        $review = ['branch_id' => $branch->id, 'idempotency_key' => $this->key(), 'note' => 'Investigated missing coins', 'confirmed' => 1];
        $this->post(route('cash.review', $session), $review)->assertRedirect();
        $this->post(route('cash.review', $session), $review)->assertRedirect();
        $this->post(route('cash.review', $session), [...$review, 'note' => 'Changed finding'])->assertStatus(409);
        $this->assertSame(-4, $session->fresh()->variance_minor);
        $this->get(route('cash.show', $session))->assertOk()->assertSee('Variance reviewed')->assertSee('Investigated missing coins');
        $this->assertDatabaseCount('cash_entries', 4);
    }

    public function test_enabled_branch_rejects_missing_foreign_and_closed_till_quotes_atomically(): void
    {
        [$branch, $session] = $this->setupTill();
        $product = $this->product($branch);
        $this->get('/pos')->assertOk()->assertSee($session->reference);
        $this->post('/pos/checkout', $this->payment($branch, $product))->assertSessionHasErrors('cash_session_id');
        $this->post('/pos/checkout', $this->payment($branch, $product, ['cash_session_id' => 999999]))->assertSessionHasErrors('cash_session_id');
        $this->post(route('cash.close', $session), $this->closing($branch, $session))->assertRedirect();
        $this->post('/cash-sessions', $this->opening($branch))->assertRedirect();
        $this->post('/pos/checkout', $this->payment($branch, $product, ['cash_session_id' => $session->id]))->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_checkout_and_refund_retries_after_closing_do_not_change_cash(): void
    {
        [$branch, $session] = $this->setupTill();
        $product = $this->product($branch);
        $pay = $this->payment($branch, $product, ['cash_session_id' => $session->id]);
        $this->post('/pos/checkout', $pay)->assertRedirect();
        $sale = Sale::firstOrFail();
        $refund = $this->returning($branch, $session, $sale);
        $this->post(route('refunds.store', $sale), $refund)->assertRedirect();
        $this->post(route('cash.close', $session), $this->closing($branch, $session->fresh(), '113.44'))->assertRedirect();
        $before = $session->fresh()->getAttributes();
        $this->post('/pos/checkout', $pay)->assertRedirect(route('sales.show', $sale));
        $this->post(route('refunds.store', $sale), $refund)->assertRedirect(route('refunds.show', Refund::firstOrFail()));
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('cash_entries', 2);
        $this->post('/pos/checkout', [...$pay, 'cash_session_id' => 0])->assertStatus(409);
    }

    public function test_stale_close_and_cash_movement_forms_require_reload_and_recount(): void
    {
        [$branch, $session] = $this->setupTill();
        $close = $this->closing($branch, $session);
        $movement = $this->movement($branch, $session);
        $this->post(route('cash.movement', $session), $movement)->assertRedirect();
        $this->post(route('cash.close', $session), $close)->assertSessionHasErrors('expected_version');
        $this->post(route('cash.movement', $session), [...$movement, 'idempotency_key' => $this->key()])->assertSessionHasErrors('expected_version');
        $this->post(route('cash.movement', $session), $movement)->assertRedirect();
        $this->post(route('cash.movement', $session), [...$movement, 'amount' => '6.00'])->assertStatus(409);
        $this->assertSame(10500, $session->fresh()->expected_minor);
        $this->assertNull($session->fresh()->closed_at);
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_insufficient_cash_refund_rolls_back_refund_and_stock_then_can_be_funded(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product))->assertRedirect(); // Historical, unassigned sale.
        $sale = Sale::firstOrFail();
        $this->post('/cash-sessions/enable', ['branch_id' => $branch->id, 'confirmed' => 1])->assertRedirect();
        $this->post('/cash-sessions', $this->opening($branch, '0'))->assertRedirect();
        $session = CashSession::firstOrFail();
        $refund = $this->returning($branch, $session, $sale);
        $this->post(route('refunds.store', $sale), $refund)->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertSame(8, $product->fresh()->stock);
        $this->post(route('cash.movement', $session), $this->movement($branch, $session, 'in', '20.00'))->assertRedirect();
        $this->post(route('refunds.store', $sale), $refund)->assertRedirect();
        $this->assertNull($sale->fresh()->cash_session_id);
        $this->assertSame($session->id, Refund::firstOrFail()->cash_session_id);
        $this->assertSame(656, $session->fresh()->expected_minor);
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_cashiers_see_only_own_tills_and_cannot_enable_move_or_review_cash(): void
    {
        [$branch, $session] = $this->setupTill(role: UserRole::Cashier);
        $this->get(route('cash.show', $session))->assertOk()->assertDontSee('Record cash in / out');
        $this->post('/cash-sessions/enable', ['branch_id' => $branch->id, 'confirmed' => 1])->assertForbidden();
        $this->post(route('cash.movement', $session), $this->movement($branch, $session))->assertForbidden();
        $this->post(route('cash.review', $session), [])->assertSessionHasErrors('branch_id');
        $this->post(route('cash.review', $session), ['branch_id' => $branch->id])->assertForbidden();
        $other = $this->signIn(UserRole::Cashier);
        $other->branches()->attach($branch);
        $this->get('/cash-sessions')->assertOk()->assertDontSee($session->reference);
        $this->get(route('cash.show', $session))->assertNotFound();
        $this->post(route('cash.close', $session), $this->closing($branch, $session))->assertNotFound();
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['cash_session_id' => $session->id]))->assertSessionHasErrors('cash_session_id');
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach($branch);
        $this->get('/cash-sessions')->assertOk()->assertSee($session->reference);
        $this->post(route('cash.close', $session), $this->closing($branch, $session))->assertRedirect();
        $this->assertSame($manager->id, $session->fresh()->closed_by);
    }

    public function test_cashier_can_open_and_close_own_till_but_cannot_rewrite_it(): void
    {
        [$branch, $session] = $this->setupTill(role: UserRole::Cashier);
        $close = $this->closing($branch, $session, '99.00');
        $this->post(route('cash.close', $session), $close)->assertRedirect();
        $this->post(route('cash.close', $session), [...$close, 'counted' => '100.00'])->assertStatus(409);
        $this->post(route('cash.close', $session), [...$close, 'idempotency_key' => $this->key()])->assertSessionHasErrors('expected_version');
        $this->post('/cash-sessions', $this->opening($branch, '10.00'))->assertRedirect();
        $this->assertSame(1000, CashSession::orderByDesc('id')->firstOrFail()->expected_minor);
        $this->assertSame(-100, $session->fresh()->variance_minor);
    }

    public function test_branch_scope_and_origin_guard_apply_to_cash_operations(): void
    {
        [$branch, $session] = $this->setupTill();
        $other = $this->branch('OTHER');
        $this->withSession(['branch_id' => $other->id]);
        $this->get(route('cash.show', $session))->assertNotFound();
        $this->get('/cash-sessions')->assertOk()->assertDontSee($session->reference);
        $this->post(route('cash.close', $session), $this->closing($branch, $session))->assertSessionHasErrors('branch_id');
        $this->post(route('cash.close', $session), $this->closing($other, $session))->assertNotFound();
        $this->assertNull($session->fresh()->closed_at);
    }

    public function test_cash_limits_negative_inputs_and_missing_confirmations_are_rejected(): void
    {
        [$branch, $session] = $this->setupTill();
        foreach (['-1', '0', '1.001', '20000000000.01'] as $amount) {
            $this->post(route('cash.movement', $session), $this->movement($branch, $session, 'in', $amount))->assertSessionHasErrors('amount');
        }
        $this->post(route('cash.movement', $session), $this->movement($branch, $session, 'out', '100.01'))->assertSessionHasErrors('cash_session_id');
        $this->post(route('cash.close', $session), $this->closing($branch, $session, '-1'))->assertSessionHasErrors('counted');
        $this->post(route('cash.close', $session), [...$this->closing($branch, $session), 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertNull($session->fresh()->closed_at);
    }

    public function test_balance_limit_failure_rolls_back_checkout(): void
    {
        [$branch, $session] = $this->setupTill('20000000000.00');
        $product = $this->product($branch);
        $this->post('/pos/checkout', $this->payment($branch, $product, ['cash_session_id' => $session->id]))->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_legacy_checkout_retry_survives_enabling_tracking(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $data = $this->payment($branch, $product);
        $this->post('/pos/checkout', $data)->assertRedirect();
        $sale = Sale::firstOrFail();
        $this->post('/cash-sessions/enable', ['branch_id' => $branch->id, 'confirmed' => 1])->assertRedirect();
        $this->post('/pos/checkout', $data)->assertRedirect(route('sales.show', $sale));
        $this->post('/pos/checkout', [...$data, 'idempotency_key' => $this->key()])->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_currency_is_locked_after_a_till_exists_even_without_products(): void
    {
        [$branch, $session] = $this->setupTill();
        $data = $this->branchData($branch->code);
        $this->put(route('branches.update', $branch), [...$data, 'currency' => 'JPY'])->assertSessionHasErrors('currency');
        $this->assertSame('BDT', $branch->fresh()->currency);
    }

    public function test_whole_yen_opening_closing_and_overage_are_exact(): void
    {
        $branch = $this->workspace();
        $branch->forceFill(['cash_tracking_enabled' => true, 'currency' => 'JPY'])->save();
        $this->post('/cash-sessions', $this->opening($branch, '100.50'))->assertSessionHasErrors('opening');
        $this->post('/cash-sessions', $this->opening($branch, '100'))->assertRedirect();
        $session = CashSession::firstOrFail();
        $this->post(route('cash.close', $session), $this->closing($branch, $session, '101'))->assertRedirect();
        $this->get(route('cash.show', $session))->assertOk()->assertSee('JPY 1');
        $this->assertSame(1, $session->fresh()->variance_minor);
    }

    public function test_history_filters_closing_date_in_branch_timezone_for_overnight_sessions(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 23:30', 'Asia/Dhaka')->utc());
        [$branch, $session] = $this->setupTill();
        $this->travel(2)->hours();
        $this->post(route('cash.close', $session), $this->closing($branch, $session))->assertRedirect();
        $this->get('/cash-sessions?basis=closed&from=2026-10-28&to=2026-10-28')->assertOk()->assertSee($session->reference);
        $this->get('/cash-sessions?basis=opened&from=2026-10-28&to=2026-10-28')->assertOk()->assertDontSee($session->reference);
        $this->get('/cash-sessions?status=pending')->assertOk()->assertDontSee($session->reference);
    }
}
