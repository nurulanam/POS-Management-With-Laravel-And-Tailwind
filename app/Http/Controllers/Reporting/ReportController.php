<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\{BranchReports, ReportPeriod};
use App\Support\{Money, ReportCsv};
use Illuminate\Http\Request;
use Illuminate\Validation\{Rule, ValidationException};

class ReportController extends Controller
{
    public const EXPORT_LIMIT = 10000;

    public function sales(Request $request, BranchReports $reports)
    {
        $branch = $request->attributes->get('currentBranch');
        $period = ReportPeriod::fromRequest($request, $branch->timezone);
        $report = $reports->snapshot(fn () => $reports->sales($branch, $period));
        return response()->view('reports.sales', [...$report, 'period' => $period, 'generated' => now('UTC')])->header('Cache-Control', 'private, no-store');
    }

    public function salesCsv(Request $request, BranchReports $reports)
    {
        $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = $request->attributes->get('currentBranch');
        $period = ReportPeriod::fromRequest($request, $branch->timezone);
        $report = $reports->snapshot(fn () => $reports->sales($branch, $period));
        $generated = now('UTC')->toIso8601String();
        $rows = [];
        foreach ($report['rows'] as $date => $row) {
            $values = [$branch->id, ReportCsv::text($branch->name), $branch->timezone, $branch->currency, $generated, $date];
            foreach ($row as $key => $value) $values[] = str_ends_with($key, '_minor') ? Money::decimal($value, $branch->currency) : $value;
            $rows[] = $values;
        }
        return ReportCsv::download("sales-branch-{$branch->id}-{$period->from}-{$period->to}.csv", [
            'branch_id', 'branch_name', 'timezone', 'currency', 'generated_at_utc', 'local_date',
            'sale_count', 'gross_before_discount_ex_tax', 'discount', 'sale_tax', 'charged_incl_tax', 'refund_count',
            'refund_ex_tax', 'refund_tax', 'refunded_incl_tax', 'net_ex_tax', 'net_tax', 'net_activity_incl_tax',
        ], $rows);
    }

    private function movementFilters(Request $request, $branch): array
    {
        return $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('branch_id', $branch->id)],
            'type' => ['nullable', Rule::in(array_keys(BranchReports::MOVEMENT_TYPES))],
        ]);
    }

    public function inventory(Request $request, BranchReports $reports)
    {
        $branch = $request->attributes->get('currentBranch');
        $period = ReportPeriod::fromRequest($request, $branch->timezone);
        $filters = $this->movementFilters($request, $branch);
        $data = $reports->snapshot(function () use ($reports, $branch, $period, $filters) {
            $query = $reports->movements($branch, $period, $filters);
            $productCount = (clone $query)->distinct()->count('m.product_id');
            $movements = $reports->movementRows($query)->paginate(50)->withQueryString();
            $selectedProduct = isset($filters['product_id']) ? $branch->products()->find($filters['product_id']) : null;
            return compact('movements', 'productCount', 'selectedProduct');
        });
        return response()->view('reports.inventory', [...$data, 'period' => $period, 'filters' => $filters, 'types' => BranchReports::MOVEMENT_TYPES, 'generated' => now('UTC')])->header('Cache-Control', 'private, no-store');
    }

    public function inventoryCsv(Request $request, BranchReports $reports)
    {
        $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = $request->attributes->get('currentBranch');
        $period = ReportPeriod::fromRequest($request, $branch->timezone);
        $filters = $this->movementFilters($request, $branch);
        $data = $reports->snapshot(fn () => $this->limited($reports->movementRows($reports->movements($branch, $period, $filters))));
        $generated = now('UTC')->toIso8601String();
        $rows = $data->map(fn ($m) => [
            $branch->id, ReportCsv::text($branch->name), $branch->timezone, $generated, $m->id,
            \Carbon\CarbonImmutable::parse($m->created_at, 'UTC')->setTimezone($branch->timezone)->toIso8601String(),
            $m->product_id, ReportCsv::text($m->sku), ReportCsv::text($m->product_name), $m->movement_type,
            (int) $m->delta, (int) $m->balance, ReportCsv::text($m->reason), $m->sale_id, $m->refund_id, $m->purchase_receipt_id, $m->stock_transfer_id, $m->transfer_receipt_id,
        ]);
        return ReportCsv::download("stock-movements-branch-{$branch->id}-{$period->from}-{$period->to}.csv", [
            'branch_id', 'branch_name', 'timezone', 'generated_at_utc', 'movement_id', 'local_timestamp', 'product_id', 'current_sku', 'current_product_name', 'type', 'quantity_change', 'recorded_balance', 'reason', 'sale_id', 'refund_id', 'purchase_receipt_id', 'stock_transfer_id', 'transfer_receipt_id',
        ], $rows);
    }

    public function lowStock(Request $request, BranchReports $reports)
    {
        $branch = $request->attributes->get('currentBranch');
        $products = $reports->snapshot(fn () => $reports->lowStock($branch)->paginate(50)->withQueryString());
        return response()->view('reports.low-stock', ['products' => $products, 'generated' => now('UTC')])->header('Cache-Control', 'private, no-store');
    }

    public function lowStockCsv(Request $request, BranchReports $reports)
    {
        $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = $request->attributes->get('currentBranch');
        $products = $reports->snapshot(fn () => $this->limited($reports->lowStock($branch)));
        $generated = now('UTC')->toIso8601String();
        $rows = $products->map(fn ($p) => [$branch->id, ReportCsv::text($branch->name), $generated, $p->id, ReportCsv::text($p->sku), ReportCsv::text($p->name), $p->stock, $p->reorder_level]);
        return ReportCsv::download("low-stock-branch-{$branch->id}.csv", ['branch_id', 'branch_name', 'generated_at_utc', 'product_id', 'current_sku', 'current_product_name', 'current_stock', 'reorder_level'], $rows);
    }

    private function limited($query)
    {
        $rows = $query->limit(self::EXPORT_LIMIT + 1)->get();
        if ($rows->count() > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'Export exceeds 10,000 rows. Narrow the movement date/product/type filters. For larger low-stock lists, use an administrator-managed database export. No partial file was generated.']);
        }
        return $rows;
    }
}
