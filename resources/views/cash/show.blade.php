@extends('layouts.management')
@section('title', 'Till reconciliation')
@section('content')
<div class="page-heading no-print"><div><span class="eyebrow">CASH DRAWER · {{ $session->owner_name }}</span><h1>A clear account of your cash.</h1><p>Expected = opening + cash sales − refunds + cash in − cash out. Sales include tax and exclude change handed back.</p></div><div class="table-actions"><a class="btn btn-outline" href="{{ route('cash.index') }}">Cash drawers</a><button type="button" class="btn btn-primary" data-print-receipt>Print reconciliation</button></div></div>
<article class="receipt panel cash-reconciliation">
<header class="receipt-header"><span class="eyebrow">{{ $session->closed_at ? 'FINAL RECONCILIATION' : 'OPEN TILL · PROVISIONAL' }}</span><h2>{{ $session->branch_name }}</h2><p class="receipt-ref">{{ $session->reference }}</p>@include('cash.status')</header>
<dl class="receipt-meta"><div><dt>Owner</dt><dd>{{ $session->owner_name }}</dd></div><div><dt>Opened</dt><dd>{{ $session->opened_at->setTimezone($session->timezone)->format('d M Y H:i:s') }}</dd></div><div><dt>Closed</dt><dd>{{ $session->closed_at?->setTimezone($session->timezone)->format('d M Y H:i:s') ?? 'Still open' }}</dd></div><div><dt>Timezone</dt><dd>{{ $session->timezone }}</dd></div>@if($session->closed_at)<div><dt>Closed by</dt><dd>{{ $session->closer_name }}</dd></div>@endif</dl>
<dl class="cart-totals">
<div><dt>Opening float</dt><dd>{{ \App\Support\Money::format($session->opening_minor, $session->currency) }}</dd></div>
@foreach(['sale' => 'Cash sales (net of change)', 'refund' => 'Cash refunds', 'in' => 'Cash in', 'out' => 'Cash out'] as $kind => $label)
<div><dt>{{ $label }}</dt><dd>{{ \App\Support\Money::format((int) $totals->get($kind, 0), $session->currency) }}</dd></div>
@endforeach
<div class="grand-total"><dt>Expected cash</dt><dd data-cash-expected>{{ \App\Support\Money::format($session->expected_minor, $session->currency) }}</dd></div>
@if($session->closed_at)
<div><dt>Counted cash</dt><dd data-cash-counted>{{ \App\Support\Money::format($session->counted_minor, $session->currency) }}</dd></div><div class="grand-total"><dt>Variance (counted − expected)</dt><dd data-cash-variance>{{ \App\Support\Money::format($session->variance_minor, $session->currency) }}</dd></div>
@endif
</dl>
@if($session->closed_at)<div class="cash-note"><strong>Closing note</strong><p>{{ $session->close_reason }}</p></div>@endif
@if($session->reviewed_at)<div class="cash-note"><strong>Reviewed by {{ $session->reviewer_name }} · {{ $session->reviewed_at->setTimezone($session->timezone)->format('d M Y H:i:s') }}</strong><p>{{ $session->review_note }}</p><small>Review acknowledges the variance; it does not adjust cash or rewrite the count.</small></div>@endif
<p class="receipt-footer">Amounts include this session's complete activity, even across midnight. Positive variance means over; negative means short. Closing does not move cash or carry a float into another session. The paginated activity ledger is not part of this printed summary.</p>
</article>
@if(!$session->closed_at)
<div class="cash-actions no-print section-gap">
@can('manage-cash')
<section class="panel"><h2>Record cash in / out</h2><p class="muted section-gap">Record physical funding, a safe drop, or a petty-cash payout. Do not duplicate sales or refunds here. This is not a transfer between tills.</p><form class="cash-operation section-gap" method="POST" action="{{ route('cash.movement', $session) }}">@csrf
<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="idempotency_key" value="{{ $movementKey }}"><input type="hidden" name="expected_version" value="{{ $session->version }}">
<div class="field"><label for="kind">Movement type</label><select name="kind" id="kind"><option value="in">Cash in</option><option value="out">Cash out</option></select></div><div class="field"><label for="amount">Movement amount ({{ $session->currency }})</label><input id="amount" name="amount" inputmode="decimal" maxlength="20" required></div><div class="field"><label for="movement-reason">Movement reason</label><textarea id="movement-reason" name="reason" required minlength="3" maxlength="500" rows="3"></textarea></div><label class="checkbox-card"><input name="confirmed" type="checkbox" value="1" required><span>I verified this physical cash movement.</span></label><button class="btn btn-primary section-gap">Record cash movement</button></form></section>
@endcan
<section class="panel"><h2>Count and close</h2><p class="muted section-gap">Stop transactions and count the physical cash. A manager can close a staff member's till if they are unavailable. Closing is final, including the entered count.</p><form id="cash-close-form" class="cash-operation section-gap" method="POST" action="{{ route('cash.close', $session) }}" data-expected="{{ $session->expected_minor }}" data-currency="{{ $session->currency }}" data-precision="{{ \App\Support\Money::precision($session->currency) }}">@csrf
<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="idempotency_key" value="{{ $closeKey }}"><input type="hidden" name="expected_version" value="{{ $session->version }}"><div class="field"><label for="counted">Counted cash ({{ $session->currency }})</label><input id="counted" name="counted" inputmode="decimal" maxlength="20" required autocomplete="off"></div><p class="info-strip" id="cash-variance-preview" role="status">Enter your physical count to preview the variance.</p><div class="field"><label for="close-reason">Closing note / discrepancy explanation</label><textarea id="close-reason" name="reason" required minlength="3" maxlength="500" rows="3"></textarea></div><label class="checkbox-card"><input name="confirmed" type="checkbox" value="1" required><span>I have counted the cash and understand this count cannot be edited.</span></label><button class="btn btn-dark section-gap">Close till</button></form></section>
</div>
@elseif($session->variance_minor && !$session->reviewed_at)
@can('manage-cash')
<section class="panel no-print section-gap"><h2>Review this variance</h2><p class="muted section-gap">Investigate the discrepancy and record your findings. The original count and variance stay unchanged; this is an acknowledgment, not a cash adjustment.</p><form class="cash-operation section-gap" method="POST" action="{{ route('cash.review', $session) }}">@csrf<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="idempotency_key" value="{{ $reviewKey }}"><div class="field"><label for="review-note">Manager review note</label><textarea id="review-note" name="note" required minlength="3" maxlength="500" rows="3"></textarea></div><label class="checkbox-card"><input type="checkbox" name="confirmed" value="1" required><span>I reviewed the discrepancy and documented my findings.</span></label><button class="btn btn-primary section-gap">Record variance review</button></form></section>
@endcan
@endif
<section class="panel table-panel no-print section-gap"><div class="panel-heading"><h2>Cash activity</h2><span class="muted">{{ $entries->total() }} entries · Newest first</span></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Time / staff</th><th>Type / source</th><th>Reason</th><th>Cash change</th><th>Balance after</th></tr></thead><tbody>
@forelse($entries as $entry)
<tr><td>{{ $entry->created_at->setTimezone($session->timezone)->format('d M Y H:i:s') }}<small class="table-note">{{ $entry->staff_name }}</small></td><td>{{ ucfirst($entry->kind) }}@if($entry->sale_id)<small class="table-note"><a class="text-link" href="{{ route('sales.show', $entry->sale_id) }}">Sale receipt ↗</a></small>@elseif($entry->refund_id)<small class="table-note"><a class="text-link" href="{{ route('refunds.show', $entry->refund_id) }}">Refund receipt ↗</a></small>@endif</td><td class="cash-note">{{ $entry->reason }}</td><td>{{ \App\Support\Money::format($entry->delta_minor, $session->currency) }}</td><td>{{ \App\Support\Money::format($entry->balance_minor, $session->currency) }}</td></tr>
@empty
<tr><td colspan="5"><div class="empty-state"><h2>No activity yet</h2><p>The opening float is recorded in the summary above.</p></div></td></tr>
@endforelse
</tbody></table></div><div class="pagination-area">{{ $entries->links() }}</div></section>
@endsection
