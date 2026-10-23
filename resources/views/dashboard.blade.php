@extends('layouts.management')
@section('title', 'Overview')
@section('content')
<div class="page-heading"><div><span class="eyebrow">LET’S GET TO WORK</span><h1>Hello, {{ auth()->user()->name }}<span class="heading-dot">.</span></h1><p>Your people, your branches, one connected workspace.</p></div><span class="badge badge-positive">● &nbsp; Account active</span></div>
@if($salesStats)
<div class="section-heading"><h2>{{ $branch->name }} · Today</h2><span class="muted">{{ auth()->user()->role === \App\Enums\UserRole::Cashier ? 'Your sales only' : 'All branch sales' }} · {{ $branch->timezone }}</span></div>
<div class="stat-grid">@foreach($salesStats as $label => $value)<article class="stat-card"><span>{{ $label }}</span><strong>{{ $value }}</strong><div class="stat-line"></div></article>@endforeach</div>
<div class="section-heading"><h2>Workspace overview</h2></div>
@endif
<div class="stat-grid">@foreach($stats as $label => $value)<article class="stat-card"><span>{{ $label }}</span><strong>{{ $value }}</strong><div class="stat-line"></div></article>@endforeach</div>
@if(!$branch)
    <section class="empty-state panel"><span class="empty-symbol" aria-hidden="true">⌂</span><h2>{{ auth()->user()->isAdmin() ? 'Your first branch starts here' : 'No active branch assigned' }}</h2><p>{{ auth()->user()->isAdmin() ? 'Set up a branch, add its business details, then bring your team on board.' : 'Ask an administrator to assign you to an active branch. Your account is ready, but branch access is not yet available.' }}</p>@can('manage-business')<a class="btn btn-primary" href="{{ route('branches.create') }}">Create your first branch →</a>@endcan</section>
@else
<div class="overview-grid">
    <section class="panel"><div class="panel-heading"><div><span class="eyebrow">CURRENT WORKSPACE</span><h2>{{ $branch->name }}</h2></div><span class="badge badge-positive">Active branch</span></div>
        <dl class="detail-grid"><div><dt>Branch code</dt><dd>{{ $branch->code }}</dd></div><div><dt>Currency</dt><dd>{{ $branch->currency }}</dd></div><div><dt>Timezone</dt><dd>{{ $branch->timezone }}</dd></div><div><dt>Tax setting</dt><dd>{{ $branch->tax_rate }}%</dd></div></dl>
        <div class="panel-bottom"><p class="muted">{{ $branch->address ?: 'An address has not been added yet.' }}</p>@can('manage-business')<a class="text-link" href="{{ route('branches.edit', $branch) }}">Manage branch settings ↗</a>@endcan</div>
    </section>
    <section class="next-panel"><span class="eyebrow">A SOLID FOUNDATION</span><h2>Ready for<br>what’s next.</h2><p>Your branch is ready for business. Open checkout to record a cash sale, or review your sales and receipts.</p><a href="{{ route('pos.index') }}" class="btn btn-outline">Open point of sale ↗</a><small>Live checkout. Stock updates when a sale completes.</small></section>
</div>
@endif
<div class="section-heading"><h2>Make yourself at home</h2><span class="muted">Quick actions for your role</span></div>
<div class="action-grid">
    @can('manage-business')
        <a class="action-card" href="{{ route('users.index') }}"><span class="action-icon">♙</span><h3>Manage your team</h3><p>Add staff, assign roles, and keep branch access up to date.</p><span class="text-link">Open user management →</span></a>
        <a class="action-card" href="{{ route('branches.index') }}"><span class="action-icon">⚙</span><h3>Make it your business</h3><p>Manage each branch’s contact details and operating preferences.</p><span class="text-link">Open branch settings →</span></a>
    @elsecan('view-team')
        @if($branch)<a class="action-card" href="{{ route('team.index') }}"><span class="action-icon">♙</span><h3>Meet your branch team</h3><p>A directory of active staff assigned to this branch.</p><span class="text-link">View branch team →</span></a>@endif
    @endcan
    <a class="action-card" href="{{ route('password.edit') }}"><span class="action-icon">⌘</span><h3>Keep your account safe</h3><p>Update your password and revoke your other sessions.</p><span class="text-link">Account security →</span></a>
</div>
@endsection
