@extends('layouts.management')
@section('title', $branch->exists ? 'Branch settings' : 'Add branch')
@section('content')
<a class="back-link" href="{{ route('branches.index') }}">← Back to branches</a>
<div class="page-heading"><div><span class="eyebrow">THE DETAILS MATTER</span><h1>{{ $branch->exists ? $branch->name : 'Make room for a new branch.' }}</h1><p>{{ $branch->exists ? 'These settings belong only to this branch.' : 'Create a location with its own business settings and staff assignments.' }}</p></div></div>
<form method="POST" action="{{ $branch->exists ? route('branches.update', $branch) : route('branches.store') }}" class="form-layout">
    @csrf @if($branch->exists) @method('PUT') @endif
    <div class="form-main">
        <section class="panel"><div class="panel-heading"><div><h2>Branch identity</h2><p class="muted">How this location appears across your workspace.</p></div><span class="step-number">01</span></div>
            <div class="form-grid"><div class="field"><label for="name">Branch name <span>*</span></label><input id="name" name="name" value="{{ old('name', $branch->name) }}" required maxlength="255" placeholder="e.g. Dhanmondi Store"></div><div class="field"><label for="code">Branch code <span>*</span></label><input id="code" name="code" value="{{ old('code', $branch->code) }}" required maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" placeholder="e.g. DHN-01"><small>A unique code. Letters, numbers, hyphens, and underscores.</small></div></div>
            <input type="hidden" name="is_active" value="0"><label class="checkbox-card" for="branch-active"><input id="branch-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch->is_active))><span>Branch is active<small>Inactive branches are unavailable to staff. Existing assignments are retained.</small></span></label>
        </section>
        <section class="panel"><div class="panel-heading"><div><h2>Contact & location</h2><p class="muted">Keep the details for this branch in one place.</p></div><span class="step-number">02</span></div>
            <div class="form-grid"><div class="field"><label for="email">Branch email</label><input id="email" name="email" type="email" value="{{ old('email', $branch->email) }}" maxlength="255"></div><div class="field"><label for="phone">Phone number</label><input id="phone" name="phone" type="tel" value="{{ old('phone', $branch->phone) }}" maxlength="40"></div></div>
            <div class="field"><label for="address">Business address</label><textarea id="address" name="address" rows="3" maxlength="1000" placeholder="Street, city, postal code">{{ old('address', $branch->address) }}</textarea></div>
        </section>
        <section class="panel"><div class="panel-heading"><div><h2>Operating preferences</h2><p class="muted">Saved for this branch. Checkout and receipt generation are not implemented yet.</p></div><span class="step-number">03</span></div>
            <div class="form-grid"><div class="field"><label for="currency">Currency <span>*</span></label><select id="currency" name="currency" required>@foreach($currencies as $code => $name)<option value="{{ $code }}" @selected(old('currency', $branch->currency) === $code)>{{ $code }} — {{ $name }}</option>@endforeach</select></div><div class="field"><label for="timezone">Timezone <span>*</span></label><select id="timezone" name="timezone" required>@foreach($timezones as $timezone)<option value="{{ $timezone }}" @selected(old('timezone', $branch->timezone) === $timezone)>{{ $timezone }}</option>@endforeach</select></div></div>
            <div class="field"><label for="tax_rate">Default tax rate (%) <span>*</span></label><input id="tax_rate" name="tax_rate" type="number" min="0" max="100" step="0.01" value="{{ old('tax_rate', $branch->tax_rate) }}" required><small>A setting only; it does not calculate tax or modify historical transactions.</small></div>
            <div class="field"><label for="receipt_footer">Receipt footer</label><textarea id="receipt_footer" name="receipt_footer" rows="3" maxlength="1000" placeholder="Thank you for shopping with us!">{{ old('receipt_footer', $branch->receipt_footer) }}</textarea><small>Plain text to use when receipts are implemented.</small></div>
        </section>
        <div class="form-actions"><a class="btn btn-outline" href="{{ route('branches.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $branch->exists ? 'Save branch settings' : 'Create branch' }} →</button></div>
    </div>
    <aside class="form-help"><span class="eyebrow">LOCAL DETAILS, CENTRAL CONTROL</span><h2>Every branch.<br>Its own identity.</h2><p>Changes here apply only to this location. Other branches keep their own currency, timezone, and tax preferences.</p><h3>Need to pause this location?</h3><p>Mark it inactive to remove staff access without deleting records. At least one active branch must remain.</p><h3>Bring your team along</h3><p>After creating a branch, use user management to assign managers and cashiers.</p><a class="text-link" href="{{ route('users.index') }}">Manage staff →</a></aside>
</form>
@endsection
