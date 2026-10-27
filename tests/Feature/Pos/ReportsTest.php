<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{InventoryMovement, Refund, Sale};
use App\Services\Reporting\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportsTest extends OperationsTestCase
{
    private function sale($branch, $product, string $at = '2026-10-28 04:00:00', array $extra = []): Sale
    {
        $this->post('/pos/checkout', $this->payment($branch, $product, $extra))->assertRedirect();
        $sale = Sale::orderByDesc('id')->firstOrFail();
        $sale->update(['paid_at' => $at]);
        return $sale;
    }

    private function refund($branch, $sale, string $at = '2026-10-29 04:00:00'): Refund
    {
        $this->post(route('refunds.store', $sale), [
            'branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Inspected return', 'confirmed' => 1,
            'items' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'expected_returned' => 0, 'restock' => false]],
        ])->assertRedirect();
        $refund = Refund::orderByDesc('id')->firstOrFail();
        $refund->update(['refunded_at' => $at]);
        return $refund;
    }

    private function csv($response): array
    {
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $file = fopen('php://temp', 'r+');
        fwrite($file, substr($response->streamedContent(), 3)); rewind($file);
        $rows = [];
        while (($row = fgetcsv($file, null, ',', '"', '')) !== false) $rows[] = $row;
        fclose($file);
        return $rows;
    }

    public function test_sales_and_refunds_use_their_own_processing_dates_and_original_values(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch);
        $sale = $this->sale($branch, $product, extra: ['discount_type' => 'fixed', 'discount_value' => '5.00', 'discount_reason' => 'Promotion']);
        $this->refund($branch, $sale);
        $product->update(['price_minor' => 99999]); $branch->update(['tax_rate' => '99.00']);
        $dayOne = $this->get('/reports?from=2026-10-28&to=2026-10-28')->assertOk();
        $this->assertSame(2500, $dayOne->viewData('totals')['gross_minor']);
        $this->assertSame(500, $dayOne->viewData('totals')['discount_minor']);
        $this->assertSame(2150, $dayOne->viewData('totals')['net_minor']);
        $dayTwo = $this->get('/reports?from=2026-10-29&to=2026-10-29')->assertOk();
        $totals = $dayTwo->viewData('totals');
        $this->assertSame(0, $totals['sale_count']);
        $this->assertSame(1, $totals['refund_count']);
        $this->assertSame(-1075, $totals['net_minor']);
        $this->assertSame(-1000, $totals['net_base_minor']);
        $this->assertSame(-75, $totals['net_tax_minor']);
        $both = $this->get('/reports?from=2026-10-28&to=2026-10-29')->assertOk()->viewData('totals');
        $this->assertSame(1075, $both['net_minor']);
        $this->assertSame($both['net_minor'], $both['net_base_minor'] + $both['net_tax_minor']);
    }

    public function test_branch_local_midnight_is_inclusive_and_next_midnight_exclusive(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch, ['stock' => 20]);
        foreach (['2026-10-27 17:59:59', '2026-10-27 18:00:00', '2026-10-28 17:59:59', '2026-10-28 18:00:00'] as $at) $this->sale($branch, $product, $at);
        $response = $this->get('/reports?from=2026-10-28&to=2026-10-28')->assertOk();
        $this->assertSame(2, $response->viewData('totals')['sale_count']);
        $this->assertSame(['2026-10-28'], array_keys($response->viewData('rows')));
    }

    public function test_daylight_saving_fall_back_groups_both_repeated_hours_in_one_local_day(): void
    {
        $branch = $this->workspace(); $branch->update(['timezone' => 'America/New_York']);
        $product = $this->product($branch, ['stock' => 20]);
        foreach (['2026-11-01 03:59:59', '2026-11-01 05:30:00', '2026-11-01 06:30:00', '2026-11-02 04:59:59', '2026-11-02 05:00:00'] as $at) $this->sale($branch, $product, $at);
        $response = $this->get('/reports?from=2026-11-01&to=2026-11-01')->assertOk();
        $this->assertSame(3, $response->viewData('totals')['sale_count']);
        $period = new ReportPeriod('2026-03-08', '2026-03-08', 'America/New_York');
        $this->assertEquals(23, $period->start->diffInHours($period->end));
    }

    public function test_default_dates_use_branch_today_and_include_zero_activity_days(): void
    {
        $this->workspace();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 20:00:00', 'UTC'));
        $response = $this->get('/reports')->assertOk()->assertSee('No activity in this period');
        $this->assertSame('2026-09-29', $response->viewData('period')->from);
        $this->assertSame('2026-10-28', $response->viewData('period')->to);
        $this->assertCount(30, $response->viewData('rows'));
    }

    public function test_date_ranges_are_validated_and_full_leap_year_is_supported(): void
    {
        $this->workspace();
        foreach (['from=2026-02-30', 'from=bad', 'from=2026-10-29&to=2026-10-28', 'from=2025-01-01&to=2026-01-02', 'from=1999-01-01', 'to=2100-01-01'] as $query) {
            $this->getJson('/reports?'.$query)->assertUnprocessable();
        }
        $response = $this->get('/reports?from=2024-01-01&to=2024-12-31')->assertOk();
        $this->assertCount(366, $response->viewData('rows'));
    }

    public function test_admin_and_manager_reports_are_limited_to_current_branch(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch);
        $this->sale($branch, $product);
        $other = $this->branch('OTHER'); $secret = $this->product($other, ['name' => 'Private stock', 'stock' => 0]);
        $manager = $this->signIn(UserRole::Manager); $manager->branches()->attach($branch);
        $this->get('/reports?from=2026-10-28&to=2026-10-28')->assertOk()->assertViewHas('totals', fn ($t) => $t['sale_count'] === 1);
        $this->get('/reports/low-stock')->assertOk()->assertDontSee('Private stock');
        $this->getJson('/reports/inventory?product_id='.$secret->id)->assertUnprocessable();
        $this->getJson('/reports/sales.csv?branch_id='.$other->id)->assertUnprocessable();
        $this->signIn();
        $this->withSession(['branch_id' => $other->id])->get('/reports?from=2026-10-28&to=2026-10-28')->assertOk()->assertViewHas('totals', fn ($t) => $t['sale_count'] === 0);
    }

    public function test_cashiers_cannot_read_reports_or_any_export(): void
    {
        $branch = $this->workspace(UserRole::Cashier);
        foreach (['/reports', '/reports/inventory', '/reports/low-stock', '/reports/sales.csv', '/reports/inventory.csv', '/reports/low-stock.csv'] as $url) {
            $this->get($url.'?branch_id='.$branch->id)->assertForbidden();
        }
        $this->get('/')->assertOk()->assertDontSee('Reports &amp; analytics', false);
    }

    public function test_exports_require_branch_context_and_reject_stale_tabs(): void
    {
        $branch = $this->workspace(); $other = $this->branch('OTHER');
        foreach (['sales', 'inventory', 'low-stock'] as $report) {
            $this->getJson('/reports/'.$report.'.csv')->assertUnprocessable();
            $this->getJson('/reports/'.$report.'.csv?branch_id='.$other->id)->assertUnprocessable();
        }
        $this->withSession(['branch_id' => $other->id])->getJson('/reports?branch_id='.$branch->id)->assertUnprocessable();
    }

    public function test_daily_csv_matches_all_screen_days_and_keeps_negative_amounts_numeric(): void
    {
        $branch = $this->workspace(); $branch->update(['name' => ' =HYPERLINK("unsafe")']);
        $product = $this->product($branch); $sale = $this->sale($branch, $product); $refund = $this->refund($branch, $sale);
        $response = $this->get('/reports/sales.csv?from=2026-10-28&to=2026-10-30&branch_id='.$branch->id);
        $csv = $this->csv($response);
        $this->assertCount(4, $csv);
        $first = array_combine($csv[0], $csv[1]); $second = array_combine($csv[0], $csv[2]);
        $this->assertStringStartsWith("'", $first['branch_name']);
        $this->assertSame('26.88', $first['charged_incl_tax']);
        $this->assertSame('-13.44', $second['net_activity_incl_tax']);
        $this->assertSame('Asia/Dhaka', $first['timezone']);
        $this->assertSame('BDT', $first['currency']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_zero_decimal_currency_exports_use_integer_amounts(): void
    {
        $branch = $this->workspace(); $branch->update(['currency' => 'JPY']);
        $product = $this->product($branch, ['price_minor' => 100]);
        $this->sale($branch, $product, extra: ['currency' => 'JPY', 'tendered' => '1000']);
        $rows = $this->csv($this->get('/reports/sales.csv?from=2026-10-28&to=2026-10-28&branch_id='.$branch->id));
        $this->assertSame('215', array_combine($rows[0], $rows[1])['charged_incl_tax']);
    }

    public function test_mixed_currency_imports_are_rejected_instead_of_silently_combined(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch);
        $this->sale($branch, $product)->update(['currency' => 'USD']);
        $this->get('/reports?from=2026-10-28&to=2026-10-28')->assertStatus(409);
        $this->get('/reports/sales.csv?from=2026-10-28&to=2026-10-28&branch_id='.$branch->id)->assertStatus(409);
    }

    public function test_movement_types_use_record_links_and_include_archived_products(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch);
        $sale = $this->sale($branch, $product);
        $product->update(['is_active' => false, 'name' => 'Renamed archived tea']);
        InventoryMovement::where('sale_id', $sale->id)->update(['created_at' => '2026-10-28 04:00:00']);
        InventoryMovement::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $sale->user_id, 'delta' => 1, 'balance' => 9, 'reason' => 'Transfer received fake text', 'created_at' => '2026-10-28 05:00:00']);
        $response = $this->get('/reports/inventory?from=2026-10-28&to=2026-10-28&type=sale')->assertOk()->assertSee('Renamed archived tea')->assertDontSee('fake text');
        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertSame(1, $response->viewData('productCount'));
        $this->get('/reports/inventory?from=2026-10-28&to=2026-10-28&type=adjustment&product_id='.$product->id)->assertOk()->assertSee('fake text');
        $this->getJson('/reports/inventory?type=unknown')->assertUnprocessable();
    }

    public function test_current_low_stock_uses_threshold_inclusively_excludes_archived_and_ignores_date_filters(): void
    {
        $branch = $this->workspace();
        $this->product($branch, ['name' => 'Empty tea', 'stock' => 0, 'reorder_level' => 0]);
        $this->product($branch, ['name' => 'Threshold tea', 'stock' => 3, 'reorder_level' => 3]);
        $this->product($branch, ['name' => 'Healthy tea', 'stock' => 4, 'reorder_level' => 3]);
        $this->product($branch, ['name' => 'Archived tea', 'stock' => 0, 'is_active' => false]);
        $this->get('/reports/low-stock?from=2000-01-01&to=2000-01-01')->assertOk()->assertSee('Empty tea')->assertSee('Threshold tea')->assertDontSee('Healthy tea')->assertDontSee('Archived tea');
        $csv = $this->csv($this->get('/reports/low-stock.csv?branch_id='.$branch->id));
        $this->assertCount(3, $csv);
    }

    public function test_inventory_csv_escapes_multiline_formula_text_and_exports_all_pages(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch, ['name' => '=SUM(1,2)']);
        $user = \App\Models\User::firstOrFail();
        for ($i = 0; $i < 51; $i++) InventoryMovement::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $user->id, 'delta' => -1, 'balance' => 0, 'reason' => "@formula,\"quoted\"\nnext line", 'created_at' => '2026-10-28 04:00:00']);
        $this->get('/reports/inventory?from=2026-10-28&to=2026-10-28')->assertOk()->assertViewHas('movements', fn ($p) => $p->count() === 50 && $p->total() === 51);
        $csv = $this->csv($this->get('/reports/inventory.csv?from=2026-10-28&to=2026-10-28&page=2&branch_id='.$branch->id));
        $this->assertCount(52, $csv);
        $row = array_combine($csv[0], $csv[1]);
        $this->assertSame("'=SUM(1,2)", $row['current_product_name']);
        $this->assertSame("'@formula,\"quoted\"\nnext line", $row['reason']);
        $this->assertSame('-1', $row['quantity_change']);
        $this->assertSame('2026-10-28T10:00:00+06:00', $row['local_timestamp']);
    }

    public function test_export_cap_rejects_before_download_and_accepts_exactly_ten_thousand(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch); $user = \App\Models\User::firstOrFail();
        $row = ['branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $user->id, 'delta' => 1, 'balance' => 1, 'reason' => 'Bulk fixture', 'created_at' => '2026-10-28 04:00:00'];
        for ($i = 0; $i < 100; $i++) DB::table('inventory_movements')->insert(array_fill(0, 100, $row));
        $extra = InventoryMovement::create($row);
        $url = '/reports/inventory.csv?from=2026-10-28&to=2026-10-28&branch_id='.$branch->id;
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('export')->assertHeaderMissing('Content-Disposition');
        $extra->delete();
        $this->assertCount(10001, $this->csv($this->get($url)));
    }

    public function test_report_requests_do_not_change_business_records(): void
    {
        $branch = $this->workspace(); $product = $this->product($branch); $sale = $this->sale($branch, $product); $this->refund($branch, $sale);
        $tables = ['products', 'sales', 'sale_items', 'refunds', 'refund_items', 'inventory_movements', 'cash_entries', 'stock_transfers'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
        $before = $snapshot();
        foreach (['/reports', '/reports/inventory', '/reports/low-stock'] as $url) $this->get($url)->assertOk();
        foreach (['sales', 'inventory', 'low-stock'] as $name) $this->csv($this->get('/reports/'.$name.'.csv?branch_id='.$branch->id));
        $this->assertEquals($before, $snapshot());
    }
}
