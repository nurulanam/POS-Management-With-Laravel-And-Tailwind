<?php

namespace App\Services\Pos;

use App\Models\RefundItem;
use App\Models\Sale;
use App\Support\Allocation;

class ReturnQuote
{
    public function lines(Sale $sale): array
    {
        $items = $sale->items()->orderBy('id')->get();
        $gross = $items->pluck('line_total_minor', 'id')->all();
        $discounts = Allocation::distribute($sale->discount_minor, $gross);
        $net = [];
        foreach ($gross as $id => $amount) {
            $net[$id] = $amount - $discounts[$id];
        }
        $tax = Allocation::distribute($sale->tax_minor, $net);
        $returned = RefundItem::whereIn('sale_item_id', array_keys($gross))
            ->selectRaw('sale_item_id, SUM(quantity) as returned_quantity')->groupBy('sale_item_id')->pluck('returned_quantity', 'sale_item_id');

        return $items->mapWithKeys(fn ($item) => [$item->id => [
            'id' => $item->id, 'product_id' => $item->product_id, 'name' => $item->name, 'sku' => $item->sku,
            'sold' => $item->quantity, 'returned' => (int) $returned->get($item->id, 0),
            'subtotal_minor' => $net[$item->id], 'tax_minor' => $tax[$item->id],
        ]])->all();
    }

    public static function portion(array $line, int $quantity): array
    {
        $before = $line['returned'];
        $after = $before + $quantity;
        $base = Allocation::roundedShare($line['subtotal_minor'], $after, $line['sold']) - Allocation::roundedShare($line['subtotal_minor'], $before, $line['sold']);
        $tax = Allocation::roundedShare($line['tax_minor'], $after, $line['sold']) - Allocation::roundedShare($line['tax_minor'], $before, $line['sold']);

        return ['subtotal_minor' => $base, 'tax_minor' => $tax, 'total_minor' => $base + $tax];
    }
}
