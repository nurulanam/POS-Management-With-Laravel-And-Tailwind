@if($currentBranch->cash_tracking_enabled)
<div class="info-strip no-print">
@if($cashSession)
Cash is recorded in your till <a class="text-link" href="{{ route('cash.show', $cashSession) }}">{{ $cashSession->reference }}</a>. Refunds are paid from this till, not the original sale's till.
@else
You need an open till before completing a cash sale or refund. <a class="text-link" href="{{ route('cash.index') }}">Open your cash drawer →</a> Then reload this form.
@endif
</div>
@endif
