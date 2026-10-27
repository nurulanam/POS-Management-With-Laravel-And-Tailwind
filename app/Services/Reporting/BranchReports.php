<?php

namespace App\Services\Reporting;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class BranchReports
{
    public const MOVEMENT_TYPES = [
        'sale' => 'Sale', 'return' => 'Return restock', 'purchase' => 'Purchase receipt',
        'transfer_in' => 'Transfer received', 'transfer_out' => 'Transfer dispatched', 'adjustment' => 'Opening / adjustment',
    ];

    // Linked records, never operator-editable reason text, determine the category.
    private const MOVEMENT_CASE = "CASE WHEN m.transfer_receipt_id IS NOT NULL THEN 'transfer_in' WHEN m.stock_transfer_id IS NOT NULL THEN 'transfer_out' WHEN m.purchase_receipt_id IS NOT NULL THEN 'purchase' WHEN m.refund_id IS NOT NULL THEN 'return' WHEN m.sale_id IS NOT NULL THEN 'sale' ELSE 'adjustment' END";

    public function snapshot(\Closure $read): mixed
    {
        // SET TRANSACTION applies only to the next transaction, not the connection's
        // session default. Keep multi-query reports coherent even on READ COMMITTED servers.
        if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        return DB::transaction($read);
    }

    public function sales(Branch $branch, ReportPeriod $period): array
    {
        // Group in SQL by precomputed UTC boundaries: portable across SQLite/MySQL,
        // observes DST, and does not require MySQL timezone tables or loading every receipt.
        $days = $period->days();
        $empty = array_fill_keys(['sale_count', 'gross_minor', 'discount_minor', 'sale_tax_minor', 'charged_minor', 'refund_count', 'refund_base_minor', 'refund_tax_minor', 'refunded_minor', 'net_base_minor', 'net_tax_minor', 'net_minor'], 0);
        $rows = array_fill_keys(array_keys($days), $empty);
        $sources = [
            ['sales', 'paid_at', 'COUNT(*) AS sale_count, SUM(subtotal_minor) AS gross_minor, SUM(discount_minor) AS discount_minor, SUM(tax_minor) AS sale_tax_minor, SUM(total_minor) AS charged_minor'],
            ['refunds', 'refunded_at', 'COUNT(*) AS refund_count, SUM(subtotal_minor) AS refund_base_minor, SUM(tax_minor) AS refund_tax_minor, SUM(total_minor) AS refunded_minor'],
        ];
        foreach ($sources as [$table, $column, $sums]) {
            $query = $period->constrain(DB::table($table)->where('branch_id', $branch->id), $column);
            // Never silently combine currencies if imported/legacy data violates branch invariants.
            abort_if((clone $query)->where('currency', '!=', $branch->currency)->exists(), 409, 'This period contains another currency. Reconcile the imported data before reporting.');
            $case = 'CASE'; $bindings = [];
            foreach ($days as $date => $end) {
                $case .= " WHEN {$column} < ? THEN ?";
                array_push($bindings, $end, $date);
            }
            $results = $query->selectRaw($case.' END AS local_date, '.$sums, $bindings)->groupBy('local_date')->get();
            foreach ($results as $result) {
                foreach ((array) $result as $key => $value) {
                    if ($key !== 'local_date') $rows[$result->local_date][$key] = (int) $value;
                }
            }
        }
        $totals = $empty;
        foreach ($rows as &$row) {
            $row['net_base_minor'] = $row['gross_minor'] - $row['discount_minor'] - $row['refund_base_minor'];
            $row['net_tax_minor'] = $row['sale_tax_minor'] - $row['refund_tax_minor'];
            $row['net_minor'] = $row['charged_minor'] - $row['refunded_minor'];
            foreach ($row as $key => $value) $totals[$key] += $value;
        }
        unset($row);

        return compact('rows', 'totals');
    }

    public function movements(Branch $branch, ReportPeriod $period, array $filters)
    {
        return $period->constrain(DB::table('inventory_movements as m')
            ->join('products as p', function ($join) { $join->on('p.id', '=', 'm.product_id')->on('p.branch_id', '=', 'm.branch_id'); })
            ->where('m.branch_id', $branch->id), 'm.created_at')
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('m.product_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->whereRaw('('.self::MOVEMENT_CASE.') = ?', [$type]));
    }

    public function movementRows($query)
    {
        return $query->select('m.*', 'p.name as product_name', 'p.sku')->selectRaw(self::MOVEMENT_CASE.' AS movement_type')->orderByDesc('m.id');
    }

    public function lowStock(Branch $branch)
    {
        return $branch->products()->where('is_active', true)->whereColumn('stock', '<=', 'reorder_level')->orderBy('stock')->orderBy('id');
    }
}
