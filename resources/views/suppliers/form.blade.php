@extends('layouts.management')
@section('title', $supplier->exists ? 'Edit supplier' : 'Add supplier')
@section('content')
<a class="back-link" href="{{ route('suppliers.index') }}">← Suppliers</a>
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }}</span><h1>{{ $supplier->exists ? 'Keep your contacts current.' : 'A reliable source starts here.' }}</h1><p>Codes must be unique within this branch, including archived suppliers.</p></div></div>
<form class="form-layout" method="POST" action="{{ $supplier->exists ? route('suppliers.update', $supplier) : route('suppliers.store') }}">@csrf
@if($supplier->exists) @method('PUT')<input type="hidden" name="expected_version" value="{{ old('expected_version', $supplier->version) }}">@endif
<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
<div class="form-main"><section class="panel">
<div class="form-grid"><div class="field"><label for="name">Supplier name *</label><input id="name" name="name" value="{{ old('name', $supplier->name) }}" maxlength="255" required></div><div class="field"><label for="code">Supplier code *</label><input id="code" name="code" value="{{ old('code', $supplier->code) }}" maxlength="60" pattern="[A-Za-z0-9._-]+" required></div></div>
<div class="field"><label for="contact_name">Contact person</label><input id="contact_name" name="contact_name" value="{{ old('contact_name', $supplier->contact_name) }}" maxlength="255"></div>
<div class="form-grid"><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $supplier->email) }}" maxlength="255"></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone', $supplier->phone) }}" maxlength="50"></div></div>
<div class="field"><label for="address">Address</label><textarea id="address" name="address" maxlength="500" rows="3">{{ old('address', $supplier->address) }}</textarea></div>
<div class="field"><label for="notes">Internal notes</label><textarea id="notes" name="notes" maxlength="1000" rows="3">{{ old('notes', $supplier->notes) }}</textarea></div>
<input type="hidden" name="is_active" value="0"><label class="checkbox-card"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active))><span>Active supplier<small>Archiving prevents new orders but does not stop receiving existing orders.</small></span></label>
</section><div class="form-actions"><a class="btn btn-outline" href="{{ route('suppliers.index') }}">Cancel</a><button class="btn btn-primary">Save supplier</button></div></div>
<aside class="form-help"><span class="eyebrow">CONTACTS, NOT ACCOUNTS PAYABLE</span><h2>Keep the source.<br>Keep the history.</h2><p>Purchase orders snapshot these details. Later edits do not rewrite the original order or its receiving records.</p><p>This phase does not record supplier payments, balances owed, or tax invoices.</p></aside>
</form>
@endsection
