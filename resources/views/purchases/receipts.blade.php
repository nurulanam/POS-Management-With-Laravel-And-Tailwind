@extends('layouts.management')
@section('title', 'Receiving history')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }} · GOODS IN</span><h1>Every delivery, accounted for.</h1><p>Processing dates use {{ $currentBranch->timezone }}. These values describe goods, not supplier payments.</p></div><a class="btn btn-outline" href="{{ route('purchases.index') }}">Purchasing</a></div>
<section class="panel table-panel"><form class="filter-bar" method="GET"><div class="field search-field"><label for="q">Receipt, delivery, or order reference</label><input id="q" name="q" maxlength="100" value="{{ request('q') }}"></div><div class="field"><label for="from">Received from</label><input id="from" name="from" type="date" value="{{ request('from') }}"></div><div class="field"><label for="to">Received through</label><input id="to" name="to" type="date" value="{{ request('to') }}"></div><button class="btn btn-dark">Filter</button><a class="text-link" href="{{ route('purchase-receipts.index') }}">Clear</a></form><div class="table-scroll"><table class="data-table"><thead><tr><th>Receipt / order</th><th>Delivery</th><th>Received</th><th>Staff</th><th>Goods value</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td><a class="text-link receipt-ref" href="{{ route('purchase-receipts.show', $receipt) }}">{{ $receipt->reference }} ↗</a><small class="table-note receipt-ref">{{ $receipt->order->reference }}</small></td><td>{{ $receipt->delivery_reference }}</td><td>{{ $receipt->received_at->setTimezone($currentBranch->timezone)->format('d M Y H:i') }}</td><td>{{ $receipt->staff_name }}</td><td>{{ \App\Support\Money::format($receipt->total_minor, $receipt->currency) }}</td></tr>
@empty
<tr><td colspan="5"><div class="empty-state"><h2>No goods receipts found</h2><p>Open a purchase order to record a delivery.</p></div></td></tr>
@endforelse
</tbody></table></div><div class="pagination-area">{{ $receipts->links() }}</div></section>
@endsection
