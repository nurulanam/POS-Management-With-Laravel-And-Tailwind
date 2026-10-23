@extends('layouts.management')
@section('title', 'Point of sale')
@section('content')
<div class="page-heading"><div><span class="eyebrow">LIVE CHECKOUT · {{ $currentBranch->name }}</span><h1>Make the next sale.</h1><p>Cash payments · {{ $currentBranch->currency }} · {{ $currentBranch->tax_rate }}% tax added to subtotal</p></div><a class="btn btn-outline" href="{{ route('sales.index') }}">Sales & receipts ↗</a></div>
<noscript><div class="notice notice-error">JavaScript is required for the checkout cart. Enable it before processing a sale.</div></noscript>
<div id="pos-app" class="checkout-layout" data-config="{{ json_encode($config) }}">
    <section class="catalog-panel"><div class="panel"><div class="field"><label for="catalog-search">Find a product</label><input id="catalog-search" type="search" maxlength="100" placeholder="Search by name, SKU, or scan a barcode" autocomplete="off"></div><p class="muted">Barcode scanners can type into this search field. Choose a product to add it.</p></div><p id="catalog-message" class="muted section-gap" role="status">Loading products…</p><div id="catalog-grid" class="catalog-grid"></div><button id="catalog-more" type="button" class="btn btn-outline btn-block section-gap" hidden>Load more products</button></section>
    <section class="panel pos-cart"><div class="panel-heading"><h2>Current sale</h2><button id="cart-clear" type="button" class="text-link">Clear cart</button></div><p id="cart-notice" class="muted" role="status"></p>
        <form id="checkout-form" method="POST" action="{{ route('pos.store') }}">@csrf
            <input type="hidden" name="branch_id" value="{{ $currentBranch->id }}"><input type="hidden" name="currency" value="{{ $currentBranch->currency }}"><input type="hidden" name="expected_tax_basis_points" value="{{ $config['taxBasisPoints'] }}"><input type="hidden" name="idempotency_key" value="{{ $checkoutKey }}">
            <div class="field"><label for="customer_id">Customer</label><select id="customer_id" name="customer_id"><option value="">Walk-in customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>@endforeach</select></div>
            <div id="cart-lines" class="cart-lines"></div><div id="cart-inputs"></div>
            <dl class="cart-totals"><div><dt>Subtotal</dt><dd id="cart-subtotal">—</dd></div><div><dt>Tax ({{ $currentBranch->tax_rate }}%)</dt><dd id="cart-tax">—</dd></div><div class="grand-total"><dt>Total</dt><dd id="cart-total">—</dd></div></dl>
            <div class="field"><label for="tendered">Cash received ({{ $currentBranch->currency }}) *</label><input id="tendered" name="tendered" inputmode="decimal" value="{{ old('tendered') }}" maxlength="20" required autocomplete="off"><small>Tax is rounded once on the subtotal. Confirm cash before completing the sale.</small></div>
            <div class="change-line">Change due <strong id="cart-change">—</strong></div><button id="checkout-submit" class="btn btn-primary btn-block" disabled>Complete cash sale →</button>
        </form><p class="muted section-gap">Switching branches or leaving this page clears an unsaved cart. Sales cannot be edited or refunded in this phase.</p>
    </section>
</div>
@endsection
