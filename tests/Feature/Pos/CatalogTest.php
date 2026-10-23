<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Product;
use Illuminate\Support\Str;

class CatalogTest extends OperationsTestCase
{
    private function details($branch, array $overrides = []): array
    {
        return array_replace([
            'branch_id' => $branch->id, 'currency' => $branch->currency, 'name' => 'Coffee', 'sku' => 'COF-01',
            'barcode' => '123456', 'category' => 'Drinks', 'price' => '12.50',
            'opening_stock' => 20, 'reorder_level' => 5, 'is_active' => true,
        ], $overrides);
    }

    public function test_manager_can_create_edit_archive_and_view_product_and_opening_stock(): void
    {
        $branch = $this->workspace(UserRole::Manager);
        $this->get('/products/create')->assertOk();
        $this->post('/products', $this->details($branch))->assertRedirect('/products');
        $product = Product::firstOrFail();
        $this->assertSame(1250, $product->price_minor);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'delta' => 20, 'balance' => 20]);
        $this->get('/products')->assertOk()->assertSee('Coffee');
        $this->get(route('products.edit', $product))->assertOk();
        $this->get(route('products.show', $product))->assertOk()->assertSee('Opening stock');
        $this->put(route('products.update', $product), $this->details($branch, ['price' => '13.05', 'is_active' => false, 'stock' => 999]))->assertRedirect('/products');
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(1305, $product->fresh()->price_minor);
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_product_validation_and_sku_uniqueness_are_branch_scoped(): void
    {
        $branch = $this->workspace();
        $this->post('/products', $this->details($branch))->assertRedirect();
        $this->post('/products', $this->details($branch))->assertSessionHasErrors(['sku', 'barcode']);
        $this->post('/products', $this->details($branch, ['sku' => 'NEW', 'barcode' => null, 'price' => '0.00']))->assertSessionHasErrors('price');
        $this->post('/products', $this->details($branch, ['sku' => 'NEW', 'barcode' => null, 'price' => '1.001']))->assertSessionHasErrors('price');
        $other = $this->branch('OTHER');
        $this->withSession(['branch_id' => $other->id])->post('/products', $this->details($other))->assertRedirect();
        $this->assertDatabaseCount('products', 2);
    }

    public function test_stock_adjustments_are_audited_idempotent_and_cannot_go_negative(): void
    {
        $branch = $this->workspace();
        $product = $this->product($branch);
        $data = ['branch_id' => $branch->id, 'delta' => -3, 'expected_stock' => 10, 'reason' => 'Damaged units', 'request_key' => (string) Str::uuid()];
        $this->post(route('products.adjust', $product), $data)->assertRedirect();
        $this->post(route('products.adjust', $product), $data)->assertRedirect();
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->post(route('products.adjust', $product), [...$data, 'delta' => 2])->assertStatus(409);
        $this->post(route('products.adjust', $product), [...$data, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('delta');
        $this->post(route('products.adjust', $product), [...$data, 'expected_stock' => 7, 'delta' => -8, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('delta');
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_cashier_cannot_mutate_stock_or_products_but_can_use_catalog(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $product = $this->product($branch);
        $this->get('/products')->assertForbidden();
        $this->post('/products', $this->details($branch))->assertForbidden();
        $this->put(route('products.update', $product), $this->details($branch))->assertForbidden();
        $this->post(route('products.adjust', $product), ['branch_id' => $branch->id])->assertForbidden();
        $this->getJson('/pos/catalog')->assertOk()->assertJsonPath('products.0.id', $product->id);
    }

    public function test_foreign_products_and_stale_branch_forms_are_rejected(): void
    {
        $branch = $this->workspace();
        $other = $this->branch('OTHER');
        $product = $this->product($other);
        $this->get(route('products.edit', $product))->assertNotFound();
        $this->put(route('products.update', $product), $this->details($branch))->assertNotFound();
        $this->post('/products', $this->details($other))->assertSessionHasErrors('branch_id');
        $this->getJson('/pos/catalog?branch_id='.$other->id)->assertUnprocessable();
        $this->getJson('/pos/catalog')->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_branch_currency_is_locked_after_catalog_is_created(): void
    {
        $branch = $this->workspace();
        $this->product($branch);
        $this->put(route('branches.update', $branch), [...$this->branchData(), 'currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->assertSame('BDT', $branch->fresh()->currency);
    }

    public function test_customer_creation_is_branch_scoped_and_only_managers_can_edit(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $this->get('/customers/create')->assertOk();
        $this->post('/customers', ['branch_id' => $branch->id, 'name' => 'Local Customer', 'is_active' => false])->assertRedirect('/customers');
        $customer = $branch->customers()->firstOrFail();
        $this->assertTrue($customer->is_active);
        $this->get('/customers')->assertOk()->assertSee('Local Customer');
        $this->get(route('customers.edit', $customer))->assertForbidden();
        $manager = $this->signIn(UserRole::Manager);
        $manager->branches()->attach($branch);
        $this->get(route('customers.edit', $customer))->assertOk();
        $this->put(route('customers.update', $customer), ['branch_id' => $branch->id, 'name' => 'Updated Customer', 'is_active' => false])->assertRedirect();
        $this->assertFalse($customer->fresh()->is_active);
        $other = $this->branch('OTHER');
        $this->signIn();
        $this->withSession(['branch_id' => $other->id])->get(route('customers.edit', $customer))->assertNotFound();
        $this->get('/customers')->assertDontSee('Updated Customer');
    }

    public function test_pos_requires_an_active_assignment_and_catalog_search_supports_barcodes(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        $this->product($branch, ['barcode' => '999888']);
        $this->get('/pos')->assertOk()->assertSee('Make the next sale.');
        $this->getJson('/pos/catalog?q=999888')->assertOk()->assertJsonCount(1, 'products');
        auth()->user()->branches()->detach();
        $this->get('/pos')->assertForbidden();
        $this->get('/sales')->assertForbidden();
        $this->get('/customers')->assertForbidden();
    }
}
