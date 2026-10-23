@extends('layouts.management')
@section('title', 'Account security')
@section('content')
<div class="page-heading"><div><span class="eyebrow">JUST FOR YOU</span><h1>A little extra peace of mind.</h1><p>Keep your workspace secure with a strong, unique password.</p></div></div>
@if(auth()->user()->must_change_password)<div class="notice" role="status"><strong>A fresh start for your account.</strong> You must replace your temporary password before accessing the workspace.</div>@endif
<form method="POST" action="{{ route('password.update') }}" class="form-layout">
    @csrf @method('PUT')
    <section class="panel form-stack"><h2>Change your password</h2><div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div><div class="field"><label for="password">New password</label><input id="password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"><small>At least 12 characters, including letters and numbers.</small></div><div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div><button type="submit" class="btn btn-primary">Update password →</button></section>
    <aside class="form-help"><span class="eyebrow">GOOD SECURITY HABITS</span><h2>Only you.<br>Only your account.</h2><p>Use a password you do not use elsewhere. Never share your sign-in with another staff member.</p><p>Changing your password signs out your other sessions and revokes existing API tokens. This session stays signed in.</p></aside>
</form>
@endsection
