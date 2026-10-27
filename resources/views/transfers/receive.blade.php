@extends('layouts.management')
@section('title', 'Receive transfer')
@section('content')
<a class="back-link" href="{{ route('transfers.show', $transfer) }}">← Transfer record</a><div class="page-heading"><div><span class="eyebrow">{{ $transfer->reference }} · TO {{ $transfer->destination_snapshot['name'] }}</span><h1>Account for every arriving unit.</h1><p>Accept sellable goods. Record damaged or confirmed-missing units separately. Leave expected later deliveries in transit.</p></div></div>
@if($transfer->status !== 'in_transit')
<div class="panel empty-state"><h2>This transfer is complete.</h2><p>No further quantities can be recorded.</p></div>
@else
<noscript><div class="notice notice-error">Enable JavaScript to review quantities and discrepancies.</div></noscript>
<form id="transfer-receive-form" class="form-layout" method="POST" action="{{ route('transfers.receive.store', $transfer) }}">@csrf<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="idempotency_key" value="{{ $receiptKey }}"><input type="hidden" name="expected_version" value="{{ $transfer->version }}">
<div class="form-main"><section class="panel"><div class="panel-heading"><h2>Count by disposition</h2><button id="transfer-accept-all" type="button" class="btn btn-outline btn-small">Accept all remaining units</button></div>
@foreach($transfer->items as $line)
@if($line->remaining() > 0)
<div class="return-line" data-transfer-line data-remaining="{{ $line->remaining() }}"><strong>{{ $line->destination_name }}</strong><small class="table-note">{{ $line->sku }} · {{ $line->quantity }} sent · {{ $line->remaining() }} in transit</small><input type="hidden" name="items[{{ $line->id }}][stock_transfer_item_id]" value="{{ $line->id }}"><div class="transfer-dispositions">
@foreach(['received_quantity' => 'Accepted', 'damaged_quantity' => 'Damaged', 'missing_quantity' => 'Confirmed missing'] as $field => $label)
<div class="field"><label for="{{ $field }}-{{ $line->id }}">{{ $label }}: {{ $line->destination_name }}</label><input id="{{ $field }}-{{ $line->id }}" data-disposition="{{ $field }}" name="items[{{ $line->id }}][{{ $field }}]" type="number" min="0" max="{{ $line->remaining() }}" step="1" required value="0"></div>
@endforeach
</div></div>
@endif
@endforeach
</section><section class="panel"><div class="field"><label for="delivery_reference">Delivery / handover reference *</label><input id="delivery_reference" name="delivery_reference" maxlength="100" required></div><div class="field"><label for="receipt-notes">Inspection / discrepancy notes *</label><textarea id="receipt-notes" name="notes" minlength="3" maxlength="500" rows="3" required></textarea></div><label class="checkbox-card"><input id="transfer-receive-confirmed" name="confirmed" type="checkbox" value="1" required><span>I inspected these units and checked the receiving history to avoid recording the same delivery twice.</span></label><div id="transfer-discrepancy" class="section-gap" hidden><label class="checkbox-card"><input id="discrepancy-confirmed" name="discrepancy_confirmed" type="checkbox" value="1"><span>I authorize these damaged/missing units to be removed from in-transit stock without adding sellable inventory, and documented why.</span></label></div></section></div>
<aside class="panel purchase-summary"><span class="eyebrow">DESTINATION STOCK</span><h2>Receiving summary</h2><p id="transfer-receive-summary" class="info-strip" role="status">Choose units to account for.</p><button id="transfer-receive-submit" class="btn btn-primary btn-block" disabled>Record transfer receipt</button><p class="muted section-gap">Only accepted units increase stock. Damaged goods are not tracked in a quarantine location; arrange physical disposal/handling separately. Missing means confirmed lost, not merely late.</p><p class="muted section-gap">Receipts and discrepancies cannot be edited or reversed. Product IDs stay fixed even after renaming or archiving. Accepted archived products stay archived.</p></aside>
</form>
@endif
@endsection
