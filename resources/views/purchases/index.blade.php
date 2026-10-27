@extends('layouts.management')
@section('title', 'Purchasing')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }} · REPLENISHMENT</span><h1>Order clearly. Receive confidently.</h1><p>Purchase orders reserve no stock and move no cash. Receive inspected goods when they arrive.</p></div><div class="table-actions"><a class="btn btn-outline" href="{{ route('purchase-receipts.index') }}">Receiving history</a><a class="btn btn-primary" href="{{ route('purchases.create') }}">+ New purchase order</a></div></div>
<section class="panel table-panel"><form class="filter-bar" method="GET"><div class="field search-field"><label for="q">Order or supplier reference</label><input id="q" name="q" maxlength="100" value="{{ request('q') }}"></div><div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach(['open' => 'Open', 'partial' => 'Partially received', 'received' => 'Fully received', 'closed' => 'Closed short'] as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="from">Ordered from</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div><div class="field"><label for="to">Ordered through</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div><button class="btn btn-dark">Filter</button><a class="text-link" href="{{ route('purchases.index') }}">Clear</a></form>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Order / supplier</th><th>Ordered / expected</th><th>Ordered goods value</th><th>Status</th></tr></thead><tbody>
@forelse($orders as $order)
<tr><td><a class="text-link receipt-ref" href="{{ route('purchases.show', $order) }}">{{ $order->reference }} ↗</a><small class="table-note">{{ $order->supplier_snapshot['name'] }} · {{ $order->supplier_reference }}</small></td><td>{{ $order->ordered_at->setTimezone($currentBranch->timezone)->format('d M Y H:i') }}<small class="table-note">Expected {{ $order->expected_on?->format('d M Y') ?? 'Not specified' }}</small></td><td>{{ \App\Support\Money::format($order->total_minor, $order->currency) }}</td><td>@include('purchases.status')</td></tr>
@empty
<tr><td colspan="4"><div class="empty-state"><h2>No purchase orders found</h2><p>Add active suppliers and products, then create an order.</p></div></td></tr>
@endforelse
</tbody></table></div><div class="pagination-area">{{ $orders->links() }}</div></section>
<p class="muted section-gap">Dates use {{ $currentBranch->timezone }}. Values exclude tax, freight, and fees; they are not amounts paid or accounts payable balances.</p>
@endsection
