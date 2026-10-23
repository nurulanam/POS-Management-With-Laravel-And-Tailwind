@extends('layouts.management')
@section('title', 'Products & stock')
@section('content')
<div class="page-heading"><div><span class="eyebrow">CATALOG & INVENTORY · {{ $currentBranch->name }}</span><h1>Keep your shelves ready.</h1><p>Branch-specific prices and whole-unit stock. Every stock change has a recorded reason.</p></div><a class="btn btn-primary" href="{{ route('products.create') }}">+ Add product</a></div>
<section class="panel table-panel">
    <div class="panel-heading"><h2>Product catalog <span class="count-pill">{{ $products->total() }}</span></h2></div>
    <form class="filter-bar" method="GET"><div class="field search-field"><label for="q">Search products</label><input id="q" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Name, SKU, or exact barcode"></div><div class="field"><label for="status">Availability</label><select id="status" name="status"><option value="">All products</option>@foreach(['active' => 'Active', 'inactive' => 'Archived', 'low' => 'Low stock'] as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></div><button class="btn btn-dark">Filter</button><a class="text-link" href="{{ route('products.index') }}">Clear</a></form>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Product</th><th>Price (excl. tax)</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($products as $product)<tr><td><strong>{{ $product->name }}</strong><small class="table-note">{{ $product->sku }} · {{ $product->category ?: 'Uncategorized' }}</small></td><td>{{ \App\Support\Money::format($product->price_minor, $currentBranch->currency) }}</td><td>{{ $product->stock }} @if($product->is_active && $product->stock <= $product->reorder_level)<span class="badge badge-warning">Low stock</span>@endif</td><td><span class="badge {{ $product->is_active ? 'badge-positive' : 'badge-neutral' }}">{{ $product->is_active ? 'Active' : 'Archived' }}</span></td><td><div class="table-actions"><a class="text-link" href="{{ route('products.edit', $product) }}">Edit</a><a class="text-link" href="{{ route('products.show', $product) }}">Stock ledger ↗</a></div></td></tr>
    @empty<tr><td colspan="5"><div class="empty-state"><h2>No products found</h2><p>Add your first product or try a different filter.</p></div></td></tr>@endforelse
    </tbody></table></div><div class="pagination-area">{{ $products->links() }}</div>
</section>
@endsection
