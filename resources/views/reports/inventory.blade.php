@extends('layouts.management')
@section('title', 'Stock movement report')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }} · INVENTORY AUDIT</span><h1>Trace the stock that moved.</h1><p>Recorded movement dates use {{ $currentBranch->timezone }}. Product names and SKUs below reflect the current catalog.</p></div><a class="btn btn-primary" href="{{ route('reports.inventory.csv', [...$period->filters(), ...$filters, 'branch_id' => $currentBranch->id]) }}">Download stock movements CSV</a></div>
@include('reports._navigation')
<section class="panel table-panel section-gap"><form class="filter-bar" method="GET" action="{{ route('reports.inventory') }}">
@include('reports._dates')
<div class="field"><label for="product_id">Product ID (optional)</label><input id="product_id" name="product_id" type="number" min="1" step="1" value="{{ $filters['product_id'] ?? '' }}" placeholder="From product ledger URL"></div>
<div class="field"><label for="type">Movement type</label><select id="type" name="type"><option value="">All types</option>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div><button class="btn btn-dark">Apply filters</button><a class="text-link" href="{{ route('reports.inventory') }}">Clear</a></form></section>
<div class="stat-grid section-gap"><article class="stat-card"><span>Matching movement records</span><strong>{{ $movements->total() }}</strong></article><article class="stat-card"><span>Distinct products moved</span><strong>{{ $productCount }}</strong></article></div>
@if($selectedProduct)<div class="info-strip">Filtered product: {{ $selectedProduct->name }} · {{ $selectedProduct->sku }} · ID {{ $selectedProduct->id }}</div>@endif
<div class="info-strip">Balances are the stock recorded immediately after each movement—not stock at the report's end date. No valuation or historical on-hand reconstruction is provided. Archived products are included. Transfer damage/loss has no destination stock movement; see <a class="text-link" href="{{ route('transfers.index') }}">transfer records</a> for those dispositions.</div>
<section class="panel table-panel section-gap"><div class="table-scroll"><table class="data-table"><thead><tr><th>Recorded date / ID</th><th>Product (current)</th><th>Type / source</th><th>Change</th><th>Recorded balance</th><th>Reason</th></tr></thead><tbody>
@forelse($movements as $movement)
<tr><td>{{ \Carbon\CarbonImmutable::parse($movement->created_at, 'UTC')->setTimezone($currentBranch->timezone)->format('d M Y H:i:s') }}<small class="table-note">#{{ $movement->id }}</small></td><td><a class="text-link" href="{{ route('products.show', $movement->product_id) }}">{{ $movement->product_name }} ↗</a><small class="table-note">{{ $movement->sku }} · ID {{ $movement->product_id }}</small></td><td>{{ $types[$movement->movement_type] }}<small class="table-note">
@if($movement->transfer_receipt_id)<a class="text-link" href="{{ route('transfers.receipt', $movement->transfer_receipt_id) }}">Transfer receipt ↗</a>
@elseif($movement->stock_transfer_id)<a class="text-link" href="{{ route('transfers.show', $movement->stock_transfer_id) }}">Transfer ↗</a>
@elseif($movement->purchase_receipt_id)<a class="text-link" href="{{ route('purchase-receipts.show', $movement->purchase_receipt_id) }}">Purchase receipt ↗</a>
@elseif($movement->refund_id)<a class="text-link" href="{{ route('refunds.show', $movement->refund_id) }}">Refund ↗</a>
@elseif($movement->sale_id)<a class="text-link" href="{{ route('sales.show', $movement->sale_id) }}">Sale ↗</a>
@endif
</small></td><td>{{ $movement->delta > 0 ? '+' : '' }}{{ $movement->delta }}</td><td>{{ $movement->balance }}</td><td class="report-reason">{{ $movement->reason }}</td></tr>
@empty
<tr><td colspan="6"><div class="empty-state"><h2>No stock movements found</h2><p>Change the date, product, or movement-type filters.</p></div></td></tr>
@endforelse
</tbody></table></div><div class="pagination-area">{{ $movements->links() }}</div></section><p class="muted section-gap">Generated {{ $generated->setTimezone($currentBranch->timezone)->format('d M Y H:i:s T') }}. Up to 366 calendar days per report; CSV exports include all matching pages up to 10,000 rows. Larger exports are rejected, never silently truncated.</p>
@endsection
