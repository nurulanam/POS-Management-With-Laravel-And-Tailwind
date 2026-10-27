@extends('layouts.management')
@section('title', 'Receive goods')
@section('content')
<a class="back-link" href="{{ route('purchases.show', $order) }}">← Purchase order</a><div class="page-heading"><div><span class="eyebrow">{{ $order->reference }} · {{ $order->supplier_snapshot['name'] }}</span><h1>Count. Inspect. Receive.</h1><p>Only record accepted, physically delivered units. Damaged or rejected goods should not be included.</p></div></div>
@if(!in_array($order->status, ['open', 'partial']))
<section class="panel empty-state"><h2>This order is no longer open for receiving.</h2></section>
@else
<noscript><div class="notice notice-error">Enable JavaScript to review and submit received quantities.</div></noscript>
<form id="purchase-receive-form" class="form-layout" method="POST" action="{{ route('purchases.receive.store', $order) }}" data-config="{{ json_encode($config) }}">@csrf<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="idempotency_key" value="{{ $receiptKey }}"><input type="hidden" name="expected_version" value="{{ $order->version }}">
<div class="form-main"><section class="panel"><div class="panel-heading"><h2>Delivered quantities</h2><button id="receive-all" type="button" class="btn btn-outline btn-small">Receive all remaining units</button></div>
@foreach($order->items as $item)
@if($item->quantity > $item->received_quantity)
<div class="return-line"><strong>{{ $item->name }}</strong><small class="table-note">{{ $item->sku }} · Ordered {{ $item->quantity }} · Already received {{ $item->received_quantity }} · Unit cost {{ \App\Support\Money::format($item->unit_cost_minor, $order->currency) }}</small><input type="hidden" name="items[{{ $item->id }}][purchase_order_item_id]" value="{{ $item->id }}"><div class="field section-gap"><label for="receive-{{ $item->id }}">Receive quantity for {{ $item->name }}</label><input id="receive-{{ $item->id }}" data-receive-line="{{ $item->id }}" type="number" name="items[{{ $item->id }}][quantity]" min="0" max="{{ $item->quantity - $item->received_quantity }}" step="1" required value="0"></div></div>
@endif
@endforeach
</section><section class="panel"><div class="field"><label for="delivery_reference">Delivery / packing-slip reference *</label><input id="delivery_reference" name="delivery_reference" required maxlength="100"></div><div class="field"><label for="receiving-notes">Receiving notes *</label><textarea id="receiving-notes" name="notes" required minlength="3" maxlength="500" rows="3" placeholder="Condition inspected, delivery details, or discrepancies"></textarea></div><label class="checkbox-card"><input id="receiving-confirmed" name="confirmed" type="checkbox" value="1" required><span>I counted and inspected these goods and checked that this delivery was not already recorded.</span></label></section></div>
<aside class="panel purchase-summary"><span class="eyebrow">GOODS RECEIVED · NOT PAYMENT</span><h2>Receiving summary</h2><dl class="cart-totals"><div class="grand-total"><dt>Goods value</dt><dd id="receiving-total">—</dd></div></dl><p id="receiving-message" class="muted" role="status">Select units to receive.</p><button id="receiving-submit" class="btn btn-primary btn-block section-gap" disabled>Record goods receipt</button><p class="muted section-gap">Stock increases immediately. Selling prices and cash drawers do not change. Receipts cannot be edited or reversed in this phase.</p><p class="muted section-gap">Original order costs apply. Archived products may be received but remain archived. After a network error, inspect receiving history before submitting another receipt.</p></aside>
</form>
@endif
@endsection
