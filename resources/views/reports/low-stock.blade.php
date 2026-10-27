@extends('layouts.management')
@section('title', 'Current low stock')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }} · REPLENISHMENT CHECK</span><h1>Know what needs attention.</h1><p>Active products currently at or below their reorder threshold. Includes zero-stock items; excludes archived products.</p></div><a class="btn btn-primary" href="{{ route('reports.low-stock.csv', ['branch_id' => $currentBranch->id]) }}">Download current low stock CSV</a></div>
@include('reports._navigation')
<div class="info-strip section-gap">This is <strong>current stock</strong>, not historical stock at a selected date. Sales-report dates and movement filters do not apply. In-transit transfers and outstanding purchases are not on-hand stock and do not reduce this list.</div>
<section class="panel table-panel section-gap"><div class="panel-heading"><h2>{{ $products->total() }} products need attention</h2></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Product / SKU</th><th>Current stock</th><th>Reorder threshold</th><th>Status</th></tr></thead><tbody>
@forelse($products as $product)
<tr><td><a class="text-link" href="{{ route('products.show', $product) }}">{{ $product->name }} ↗</a><small class="table-note">{{ $product->sku }} · ID {{ $product->id }}</small></td><td>{{ $product->stock }}</td><td>{{ $product->reorder_level }}</td><td><span class="badge badge-warning">{{ $product->stock === 0 ? 'Out of stock' : 'At or below threshold' }}</span></td></tr>
@empty
<tr><td colspan="4"><div class="empty-state"><h2>No active products at low stock</h2><p>Reorder thresholds are configured in Products &amp; stock.</p></div></td></tr>
@endforelse
</tbody></table></div><div class="pagination-area">{{ $products->links() }}</div></section><p class="muted section-gap">Generated {{ $generated->setTimezone($currentBranch->timezone)->format('d M Y H:i:s T') }}. CSV includes all pages up to 10,000 products; larger exports are rejected.</p>
@endsection
