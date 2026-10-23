@extends('layouts.management')
@section('title', 'Record cash refund')
@section('content')
<a class="back-link" href="{{ route('sales.show', $sale) }}">← Original sale receipt</a>
<div class="page-heading"><div><span class="eyebrow">CONTROLLED RETURNS · {{ $sale->reference }}</span><h1>Make it right. Keep the record.</h1><p>Refund original amounts after discounts and tax. Inspect returned items before marking them for restocking.</p></div><span class="badge badge-warning">Manager / admin only</span></div>
@if(collect($lines)->sum(fn ($line) => $line['sold'] - $line['returned']) === 0)
<div class="panel empty-state"><h2>All units have already been returned.</h2><p>No additional refund can be recorded against this sale.</p></div>
@else
<noscript><div class="notice">Enable JavaScript to review the refund amount and submit this form. The server always calculates the final amount.</div></noscript>
<form id="refund-form" class="form-layout" method="POST" action="{{ route('refunds.store', $sale) }}" data-config="{{ json_encode($config) }}">
@csrf
<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="idempotency_key" value="{{ $refundKey }}">
<div class="form-main"><section class="panel"><div class="panel-heading"><div><h2>Select returned units</h2><p class="muted">Partial returns are supported. A full return uses all remaining units.</p></div><button id="refund-select-all" class="btn btn-outline btn-small" type="button">Return all remaining units</button></div>
@foreach($lines as $line)
@if($line['sold'] > $line['returned'])
<div class="return-line" data-line-id="{{ $line['id'] }}">
<div><strong>{{ $line['name'] }}</strong><small class="table-note">{{ $line['sku'] }} · Sold {{ $line['sold'] }} · Already returned {{ $line['returned'] }}</small></div>
<input type="hidden" name="items[{{ $line['id'] }}][sale_item_id]" value="{{ $line['id'] }}"><input type="hidden" name="items[{{ $line['id'] }}][expected_returned]" value="{{ $line['returned'] }}">
<div class="form-grid"><div class="field"><label for="quantity-{{ $line['id'] }}">Return quantity for {{ $line['name'] }}</label><input id="quantity-{{ $line['id'] }}" data-return-quantity type="number" name="items[{{ $line['id'] }}][quantity]" min="0" max="{{ $line['sold'] - $line['returned'] }}" step="1" required value="{{ old('items.'.$line['id'].'.quantity', 0) }}"></div><div class="field"><label>Stock disposition</label><input type="hidden" name="items[{{ $line['id'] }}][restock]" value="0"><label class="checkbox-card" for="restock-{{ $line['id'] }}"><input id="restock-{{ $line['id'] }}" type="checkbox" name="items[{{ $line['id'] }}][restock]" value="1" @checked(old('items.'.$line['id'].'.restock', false))><span>Restock {{ $line['name'] }}<small>Only checked items increase sellable stock.</small></span></label></div></div>
<p class="muted">Refund for selected units: <strong data-line-refund>—</strong></p>
</div>
@endif
@endforeach
</section><section class="panel"><div class="field"><label for="reason">Refund reason *</label><textarea id="reason" name="reason" required minlength="3" maxlength="500" rows="3" placeholder="Explain the return, issue, or pricing exception">{{ old('reason') }}</textarea></div><label class="checkbox-card" for="confirmed"><input id="confirmed" name="confirmed" type="checkbox" value="1" required><span>I authorize this cash refund and have checked the selected restock items.</span></label></section></div>
<aside class="panel refund-total-panel"><span class="eyebrow">CASH TO HAND BACK</span><h2>Refund summary</h2><dl class="cart-totals"><div><dt>Net goods (after discount)</dt><dd id="refund-subtotal">—</dd></div><div><dt>Original tax</dt><dd id="refund-tax">—</dd></div><div class="grand-total"><dt>Cash refund</dt><dd id="refund-total">—</dd></div></dl><p id="refund-message" class="muted" role="status">Choose at least one unit.</p><button id="refund-submit" disabled class="btn btn-primary btn-block section-gap" type="submit">Record cash refund →</button><p class="muted section-gap">This records cash handed back; it does not initiate a bank or provider payment. Refunds cannot be edited or reversed in this phase.</p><p class="muted section-gap">Amounts use the original sale, not current prices. Rounding pennies are reconciled across partial returns so a full return never exceeds the original payment.</p></aside>
</form>
@endif
@endsection
