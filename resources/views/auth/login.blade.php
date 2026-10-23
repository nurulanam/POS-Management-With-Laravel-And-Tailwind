<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Sunset Sales</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="management">
<main class="login-shell">
    <section class="login-story">
        <a class="workspace-brand" href="{{ route('login') }}"><span class="brand-mark">S</span><span>sunset<span class="brand-light">sales</span></span></a>
        <div class="login-copy"><span class="eyebrow">YOUR BUSINESS, IN FOCUS</span><h1>Good business<br>starts with<br>a great team<span>.</span></h1><p>People, branches, and the details that make your business yours. Together in one workspace.</p></div>
        <p class="login-caption">A little more organized. A lot more possible.</p>
    </section>
    <section class="login-panel">
        <div class="login-card">
            <span class="eyebrow">WELCOME BACK</span><h2>Sign in to your workspace</h2><p class="muted">Use the account provided by your administrator.</p>
            @if($errors->any())<div class="notice notice-error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('login.store') }}" class="form-stack">
                @csrf
                <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="255" placeholder="you@yourbusiness.com" @if($errors->has('email')) aria-invalid="true" @endif></div>
                <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password" maxlength="255"></div>
                <button class="btn btn-primary btn-block" type="submit">Sign in <span aria-hidden="true">→</span></button>
            </form>
            <div class="login-help"><strong>Need access or a password reset?</strong><p>Contact your business administrator. Accounts are invitation-only; there is no public registration.</p></div>
        </div>
    </section>
</main>
</body>
</html>
