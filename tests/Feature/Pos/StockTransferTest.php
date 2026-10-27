<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{StockTransfer, TransferReceipt};
use App\Services\Inventory\TransferStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferTest extends OperationsTestCase
{
    private function setupRoute(): array
    {
        $source = $this->workspace();
        $destination = $this->branch('DEST');
        $from = $this->product($source, ['sku' => 'SHARED', 'stock' => 10]);
        $to = $this->product($destination, ['sku' => 'SHARED', 'name' => 'Destination Tea', 'stock' => 3]);
        return [$source, $destination, $from, $to];
    }

    private function dispatchData($source, $destination, $from, $to, int $quantity = 5): array
    {
        return ['branch_id' => $source->id, 'destination_branch_id' => $destination->id,
            'idempotency_key' => (string) Str::uuid(), 'notes' => 'Inspected and handed to courier', 'confirmed' => 1,
            'items' => [['source_product_id' => $from->id, 'destination_product_id' => $to->id, 'quantity' => $quantity]]];
    }

    private function sent(): array
    {
        [$source, $destination, $from, $to] = $this->setupRoute();
        $this->post('/transfers', $this->dispatchData($source, $destination, $from, $to))->assertRedirect();
        return [$source, $destination, $from, $to, StockTransfer::firstOrFail()];
    }

    private function receiptData($destination, $transfer, int $accepted = 2, int $damaged = 0, int $missing = 0): array
    {
        return ['branch_id' => $destination->id, 'expected_version' => $transfer->version, 'idempotency_key' => (string) Str::uuid(),
            'delivery_reference' => 'HANDOVER-1', 'notes' => 'Inspected destination delivery', 'confirmed' => 1, 'discrepancy_confirmed' => 1,
            'items' => [['stock_transfer_item_id' => $transfer->items()->firstOrFail()->id, 'received_quantity' => $accepted, 'damaged_quantity' => $damaged, 'missing_quantity' => $missing]]];
    }

    public function test_dispatch_deducts_only_source_and_snapshots_identity_without_financial_entries(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $this->assertSame(5, $from->fresh()->stock);
        $this->assertSame(3, $to->fresh()->stock);
        $this->assertSame(5, $transfer->items()->first()->remaining());
        $this->assertDatabaseHas('inventory_movements', ['stock_transfer_id' => $transfer->id, 'branch_id' => $source->id, 'delta' => -5, 'balance' => 5]);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('sales', 0);
        $from->update(['name' => 'New source name']);
        $to->update(['name' => 'New destination name', 'sku' => 'RENAMED', 'is_active' => false]);
        $source->update(['name' => 'New store name']);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Premium Tea')->assertSee('Destination Tea')->assertSee('SHARED');
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertRedirect();
        $this->assertSame(5, $to->fresh()->stock);
        $this->assertFalse($to->fresh()->is_active);
    }

    public function test_partial_receiving_and_discrepancies_conserve_dispatched_units(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $this->withSession(['branch_id' => $destination->id])->get(route('transfers.receive', $transfer))->assertOk();
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer, 2, 1))->assertRedirect();
        $this->assertSame('in_transit', $transfer->fresh()->status);
        $this->assertSame(2, $transfer->items()->first()->remaining());
        $this->assertSame(5, $to->fresh()->stock);
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer->fresh(), 1, 0, 1))->assertRedirect();
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertNotNull($transfer->fresh()->completed_at);
        $line = $transfer->items()->first();
        $this->assertSame(0, $line->remaining());
        $this->assertSame(3, $line->received_quantity);
        $this->assertSame(1, $line->damaged_quantity);
        $this->assertSame(1, $line->missing_quantity);
        $this->assertSame(13, $from->fresh()->stock + $to->fresh()->stock + $line->damaged_quantity + $line->missing_quantity);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Completed');
        $this->get(route('transfers.receipt', TransferReceipt::firstOrFail()))->assertOk()->assertSee('Destination Tea');
    }

    public function test_full_loss_consumes_transit_without_destination_stock_movements(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer, 0, 2, 3))->assertRedirect();
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame(3, $to->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('transfer_receipts', 1);
    }

    public function test_over_receiving_stale_forms_and_missing_discrepancy_authorization_fail(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $this->withSession(['branch_id' => $destination->id]);
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer, 3, 2, 1))->assertSessionHasErrors('items');
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer, 0))->assertSessionHasErrors('items');
        $bad = $this->receiptData($destination, $transfer, 2, 1);
        unset($bad['discrepancy_confirmed']);
        $this->post(route('transfers.receive.store', $transfer), $bad)->assertSessionHasErrors('discrepancy_confirmed');
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertRedirect();
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertSessionHasErrors('expected_version');
        $this->assertSame(5, $to->fresh()->stock);
        $this->assertDatabaseCount('transfer_receipts', 1);
    }

    public function test_dispatch_and_receipt_replays_are_exact_and_actor_bound(): void
    {
        [$source, $destination, $from, $to] = $this->setupRoute();
        $data = $this->dispatchData($source, $destination, $from, $to);
        $this->post('/transfers', $data)->assertRedirect();
        $transfer = StockTransfer::firstOrFail();
        $this->post('/transfers', $data)->assertRedirect(route('transfers.show', $transfer));
        $this->post('/transfers', [...$data, 'notes' => 'Different dispatch'])->assertStatus(409);
        $receiptData = $this->receiptData($destination, $transfer, 5);
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $receiptData)->assertRedirect();
        $this->post(route('transfers.receive.store', $transfer), $receiptData)->assertRedirect(route('transfers.receipt', TransferReceipt::firstOrFail()));
        $this->post(route('transfers.receive.store', $transfer), [...$receiptData, 'notes' => 'Different receipt'])->assertStatus(409);
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach([$source->id, $destination->id]);
        $this->post(route('transfers.receive.store', $transfer), $receiptData)->assertForbidden();
        $this->withSession(['branch_id' => $source->id])->post('/transfers', $data)->assertForbidden();
        $this->assertSame(5, $from->fresh()->stock);
        $this->assertSame(8, $to->fresh()->stock);
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertDatabaseCount('transfer_receipts', 1);
    }

    public function test_dispatch_requires_both_branches_but_destination_only_manager_can_receive(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach($destination);
        $this->withSession(['branch_id' => $destination->id]);
        $this->get('/transfers')->assertOk()->assertSee($transfer->reference);
        $this->get(route('transfers.show', $transfer))->assertOk();
        $this->get('/transfers/catalog?destination_branch_id='.$source->id)->assertNotFound();
        $this->post('/transfers', $this->dispatchData($destination, $source, $to, $from, 1))->assertForbidden();
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertRedirect();
        $this->assertSame(5, $to->fresh()->stock);
        $this->get(route('products.show', $from))->assertNotFound();
    }

    public function test_source_cannot_receive_and_unrelated_branches_cannot_view_or_write(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $this->get(route('transfers.receive', $transfer))->assertForbidden();
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($source, $transfer))->assertForbidden();
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertRedirect();
        $other = $this->branch('OTHER');
        $this->withSession(['branch_id' => $other->id])->get(route('transfers.show', $transfer))->assertNotFound();
        $this->get(route('transfers.receipt', TransferReceipt::firstOrFail()))->assertNotFound();
        $this->get('/transfers')->assertOk()->assertDontSee($transfer->reference);
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertSessionHasErrors('branch_id');
        $this->post(route('transfers.receive.store', $transfer), $this->receiptData($other, $transfer))->assertNotFound();
    }

    public function test_cashiers_cannot_use_transfer_routes(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $cashier = $this->signIn(UserRole::Cashier); $cashier->branches()->attach([$source->id, $destination->id]);
        foreach (['/transfers', '/transfers/create', '/transfers/catalog?destination_branch_id='.$destination->id, route('transfers.show', $transfer)] as $url) $this->get($url)->assertForbidden();
        $this->post('/transfers', $this->dispatchData($source, $destination, $from, $to))->assertForbidden();
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertForbidden();
    }

    public function test_product_mappings_and_source_stock_are_validated_atomically(): void
    {
        [$source, $destination, $from, $to] = $this->setupRoute();
        $this->post('/transfers', $this->dispatchData($source, $source, $from, $from))->assertSessionHasErrors('destination_branch_id');
        $this->post('/transfers', $this->dispatchData($source, $destination, $from, $from))->assertSessionHasErrors('items');
        $this->post('/transfers', $this->dispatchData($source, $destination, $from, $to, 11))->assertSessionHasErrors('items');
        $to->update(['sku' => 'WRONG']);
        $this->post('/transfers', $this->dispatchData($source, $destination, $from, $to))->assertSessionHasErrors('items');
        $to->update(['sku' => 'SHARED', 'is_active' => false]);
        $this->post('/transfers', $this->dispatchData($source, $destination, $from, $to))->assertSessionHasErrors('items');
        $this->assertSame(10, $from->fresh()->stock);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_destination_stock_limit_failure_preserves_transit_and_all_stock(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $to->update(['stock' => 1000000000]);
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer))->assertSessionHasErrors('items');
        $this->assertSame(5, $transfer->items()->first()->remaining());
        $this->assertSame(0, $transfer->fresh()->version);
        $this->assertDatabaseCount('transfer_receipts', 0);
        $this->assertSame(1000000000, $to->fresh()->stock);
    }

    public function test_duplicate_foreign_and_negative_lines_are_rejected(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $dispatch = $this->dispatchData($source, $destination, $from, $to);
        $dispatch['items'][] = $dispatch['items'][0];
        $this->post('/transfers', $dispatch)->assertSessionHasErrors('items.0.source_product_id');
        $this->withSession(['branch_id' => $destination->id]);
        $data = $this->receiptData($destination, $transfer); $data['items'][0]['stock_transfer_item_id'] = 999999;
        $this->post(route('transfers.receive.store', $transfer), $data)->assertSessionHasErrors('items');
        $data = $this->receiptData($destination, $transfer, -1);
        $this->post(route('transfers.receive.store', $transfer), $data)->assertSessionHasErrors('items.0.received_quantity');
        $data = $this->receiptData($destination, $transfer); $data['items'][] = $data['items'][0];
        $this->post(route('transfers.receive.store', $transfer), $data)->assertSessionHasErrors('items.0.stock_transfer_item_id');
        $this->assertDatabaseCount('transfer_receipts', 0);
    }

    public function test_in_transit_branches_cannot_be_deactivated_until_all_units_are_accounted_for(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        foreach ([$source, $destination] as $branch) {
            $this->put(route('branches.update', $branch), [...$this->branchData($branch->code), 'is_active' => false])->assertSessionHasErrors(['is_active' => 'Resolve all in-transit transfers before deactivating this branch.']);
        }
        $this->withSession(['branch_id' => $destination->id])->post(route('transfers.receive.store', $transfer), $this->receiptData($destination, $transfer, 5))->assertRedirect();
        $this->put(route('branches.update', $source), [...$this->branchData($source->code), 'is_active' => false])->assertRedirect();
        $this->assertFalse($source->fresh()->is_active);
        $this->get(route('transfers.receipt', TransferReceipt::firstOrFail()))->assertOk();
    }

    public function test_filters_use_branch_local_dispatch_date_and_direction(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 00:30', 'Asia/Dhaka')->utc());
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $this->get('/transfers?direction=outgoing&from=2026-10-27&to=2026-10-27')->assertOk()->assertSee($transfer->reference);
        $this->get('/transfers?direction=incoming')->assertOk()->assertDontSee($transfer->reference);
        $this->get('/transfers?to=2026-10-26')->assertOk()->assertDontSee($transfer->reference);
        $this->withSession(['branch_id' => $destination->id])->get('/transfers?direction=incoming')->assertOk()->assertSee($transfer->reference);
    }

    public function test_service_rechecks_revoked_destination_access_and_role(): void
    {
        [$source, $destination, $from, $to, $transfer] = $this->sent();
        $manager = $this->signIn(UserRole::Manager); $manager->branches()->attach($destination);
        $manager->branches()->detach($destination);
        try {
            app(TransferStock::class)->receive($destination, $transfer, $manager, $this->receiptData($destination, $transfer));
            $this->fail('Revoked membership must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $manager->branches()->attach($destination);
        DB::table('users')->where('id', $manager->id)->update(['role' => 'cashier']);
        try {
            app(TransferStock::class)->receive($destination, $transfer, $manager, $this->receiptData($destination, $transfer));
            $this->fail('Demoted manager must be rejected.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) { $this->assertDatabaseCount('transfer_receipts', 0); }
    }
}
