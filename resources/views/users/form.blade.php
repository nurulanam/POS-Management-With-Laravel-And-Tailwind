@extends('layouts.management')
@section('title', $staff->exists ? 'Edit staff member' : 'Add staff member')
@section('content')
<a class="back-link" href="{{ route('users.index') }}">← Back to user management</a>
<div class="page-heading"><div><span class="eyebrow">PEOPLE & PERMISSIONS</span><h1>{{ $staff->exists ? 'Keep their access up to date.' : 'Bring someone on board.' }}</h1><p>{{ $staff->exists ? 'Changes take effect immediately and revoke previous sessions.' : 'Create a staff account and choose where they can work.' }}</p></div></div>
<form method="POST" action="{{ $staff->exists ? route('users.update', $staff) : route('users.store') }}" class="form-layout">
    @csrf @if($staff->exists) @method('PUT') @endif
    <div class="form-main">
        <section class="panel"><div class="panel-heading"><div><h2>Staff details</h2><p class="muted">Their name and email are used throughout the workspace.</p></div><span class="step-number">01</span></div>
            <div class="form-grid"><div class="field"><label for="name">Full name <span>*</span></label><input id="name" name="name" value="{{ old('name', $staff->name) }}" required maxlength="255" autocomplete="name"></div>
            <div class="field"><label for="email">Email address <span>*</span></label><input id="email" name="email" type="email" value="{{ old('email', $staff->email) }}" required maxlength="255" autocomplete="off"></div></div>
            <div class="form-grid"><div class="field"><label for="staff-role">Role <span>*</span></label><select id="staff-role" name="role" required>@foreach($roles as $role)<option value="{{ $role->value }}" @selected(old('role', $staff->role->value) === $role->value)>{{ $role->label() }}</option>@endforeach</select></div>
            <div class="field"><label>Account access</label><input type="hidden" name="is_active" value="0"><label class="checkbox-card" for="is_active"><input id="is_active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $staff->is_active))><span>Active account<small>Inactive accounts cannot sign in or use API tokens.</small></span></label></div></div>
        </section>
        <section class="panel"><div class="panel-heading"><div><h2>Branch assignments</h2><p class="muted">Active managers and cashiers need at least one active branch. Administrators automatically access all branches.</p></div><span class="step-number">02</span></div>
            <div class="branch-options">@forelse($branches as $branch)<label class="checkbox-card" for="branch-{{ $branch->id }}"><input id="branch-{{ $branch->id }}" type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branch_ids', $assigned)))><span>{{ $branch->name }}<small>{{ $branch->code }}</small></span></label>@empty<div class="notice">Create an active branch before adding managers or cashiers. <a class="text-link" href="{{ route('branches.create') }}">Create a branch →</a></div>@endforelse</div>
        </section>
        <section class="panel"><div class="panel-heading"><div><h2>{{ $staff->exists ? 'Reset password (optional)' : 'Temporary password' }}</h2><p class="muted">{{ $staff->exists ? 'Leave both fields empty to keep the current password.' : 'Share the password securely. The staff member must change it on first sign-in.' }} Minimum 12 characters, including letters and numbers.</p></div><span class="step-number">03</span></div>
            <div class="form-grid"><div class="field"><label for="staff-password">{{ $staff->exists ? 'New temporary password' : 'Temporary password *' }}</label><input type="password" id="staff-password" name="password" autocomplete="new-password" minlength="12" maxlength="72" @required(!$staff->exists)></div><div class="field"><label for="password-confirmation">Confirm password</label><input type="password" id="password-confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" @required(!$staff->exists)></div></div>
        </section>
        <div class="form-actions"><a class="btn btn-outline" href="{{ route('users.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $staff->exists ? 'Save staff changes' : 'Create staff account' }} →</button></div>
    </div>
    <aside class="form-help"><span class="eyebrow">THE RIGHT LEVEL OF ACCESS</span><h2>Clear roles.<br>Confident teams.</h2><dl><dt>Admin</dt><dd>Manages all users, branches, and business settings.</dd><dt>Manager</dt><dd>Accesses assigned branches and their team directories. Cannot edit users or settings.</dd><dt>Cashier</dt><dd>Processes cash sales in assigned branches and views their own receipts. Cannot edit inventory or settings.</dd></dl><p>You cannot deactivate or demote your own administrator account.</p></aside>
</form>
@endsection
