@extends('layouts.management')
@section('title', 'Reports & analytics')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }} · {{ $currentBranch->currency }}</span><h1>The numbers behind the day.</h1><p>Sales charged and refunds processed in this period, grouped by {{ $currentBranch->timezone }} calendar date.</p></div><a class="btn btn-primary" href="{{ route('reports.sales.csv', [...$period->filters(), 'branch_id' => $currentBranch->id]) }}">Download daily sales CSV</a></div>
@include('reports._navigation')
<section class="panel table-panel section-gap"><form class="filter-bar" method="GET" action="{{ route('reports.sales') }}">
@include('reports._dates')
<button class="btn btn-dark">Apply dates</button><a class="text-link" href="{{ route('reports.sales') }}">Last 30 days</a><p class="muted">Both dates inclusive · maximum 366 days</p></form></section>
<div class="stat-grid section-gap">
<article class="stat-card"><span>Charged · including tax</span><strong data-report="charged">{{ \App\Support\Money::format($totals['charged_minor'], $currentBranch->currency) }}</strong><small>{{ $totals['sale_count'] }} sale receipts</small></article>
<article class="stat-card"><span>Refunds processed · including tax</span><strong data-report="refunded">{{ \App\Support\Money::format($totals['refunded_minor'], $currentBranch->currency) }}</strong><small>{{ $totals['refund_count'] }} refund receipts</small></article>
<article class="stat-card"><span>Net activity · including tax</span><strong data-report="net">{{ \App\Support\Money::format($totals['net_minor'], $currentBranch->currency) }}</strong><small>Charged minus refunds processed</small></article>
<article class="stat-card"><span>Discounts granted on sales</span><strong data-report="discount">{{ \App\Support\Money::format($totals['discount_minor'], $currentBranch->currency) }}</strong><small>Original discounts, not reversed by refunds</small></article>
</div>
<div class="info-strip">Refunds follow their <strong>processing date</strong>, even when the sale was earlier. Net activity can be negative. This differs from <a class="text-link" href="{{ route('sales.index', [...$period->filters(), 'branch_id' => $currentBranch->id]) }}">sale-date history</a>, which subtracts all refunds against matching sales. These are not profit, till reconciliation, or statutory tax figures.</div>
<section class="panel section-gap"><h2>Period reconciliation</h2><dl class="report-reconciliation">
@foreach(['gross_minor' => 'Gross sales before discount, excluding tax', 'discount_minor' => 'Less: discounts granted', 'refund_base_minor' => 'Less: refunds excluding tax', 'net_base_minor' => 'Net activity excluding tax', 'sale_tax_minor' => 'Tax on sales', 'refund_tax_minor' => 'Less: tax refunded', 'net_tax_minor' => 'Net tax activity'] as $key => $label)
<div><dt>{{ $label }}</dt><dd>{{ \App\Support\Money::format($totals[$key], $currentBranch->currency) }}</dd></div>
@endforeach
</dl></section>
<section class="panel table-panel section-gap"><div class="panel-heading"><div><h2>Daily activity</h2><p class="muted">{{ $period->from }} → {{ $period->to }} · {{ count($rows) }} calendar days · Amounts in {{ $currentBranch->currency }}</p></div></div>
@if($totals['sale_count'] + $totals['refund_count'] === 0)
<div class="empty-state"><h2>No activity in this period</h2><p>Zero-activity dates are included in the table and export.</p></div>
@endif
<div class="table-scroll"><table class="data-table report-daily"><thead><tr><th>Local date</th><th>Sales / refunds</th><th>Gross excl. tax</th><th>Discounts</th><th>Charged incl. tax</th><th>Refunded incl. tax</th><th>Net excl. tax</th><th>Net tax</th><th>Net incl. tax</th></tr></thead><tbody>
@foreach($rows as $date => $row)
<tr><td>{{ $date }}</td><td><a class="text-link" aria-label="Sales on {{ $date }}" href="{{ route('sales.index', ['from' => $date, 'to' => $date, 'branch_id' => $currentBranch->id]) }}">{{ $row['sale_count'] }} sales</a><small class="table-note"><a class="text-link" aria-label="Refunds on {{ $date }}" href="{{ route('refunds.index', ['from' => $date, 'to' => $date, 'branch_id' => $currentBranch->id]) }}">{{ $row['refund_count'] }} refunds</a></small></td>
@foreach(['gross_minor', 'discount_minor', 'charged_minor', 'refunded_minor', 'net_base_minor', 'net_tax_minor', 'net_minor'] as $key)
<td>{{ \App\Support\Money::decimal($row[$key], $currentBranch->currency) }}</td>
@endforeach
</tr>
@endforeach
</tbody></table></div></section>
<p class="muted section-gap">Generated {{ $generated->setTimezone($currentBranch->timezone)->format('d M Y H:i:s T') }}. Refresh or export to query again; separate requests may include newly committed activity. Amounts use original transaction values, not today's prices or tax rates.</p>
@endsection
