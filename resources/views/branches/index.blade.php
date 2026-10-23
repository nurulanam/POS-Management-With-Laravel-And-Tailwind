@extends('layouts.management')
@section('title', 'Branches & settings')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ONE BUSINESS. EVERY LOCATION.</span><h1>Set up for the way you work.</h1><p>Each branch has its own team, contact information, and operating preferences.</p></div><a class="btn btn-primary" href="{{ route('branches.create') }}">+ Add branch</a></div>
<div class="section-heading"><h2>Your branches <span class="count-pill">{{ $branches->total() }}</span></h2><span class="muted">All business locations</span></div>
<div class="branch-grid">@forelse($branches as $branch)
    <article class="panel branch-card"><div class="branch-card-top"><span class="branch-icon" aria-hidden="true">⌂</span><span @class(['badge', 'badge-positive' => $branch->is_active, 'badge-neutral' => !$branch->is_active])>{{ $branch->is_active ? 'Active' : 'Inactive' }}</span></div><span class="eyebrow">{{ $branch->code }}</span><h2>{{ $branch->name }}</h2><p class="branch-address">{{ $branch->address ?: 'No address added yet.' }}</p>
    <dl class="branch-summary"><div><dt>Assigned staff</dt><dd>{{ $branch->users_count }}</dd></div><div><dt>Currency</dt><dd>{{ $branch->currency }}</dd></div><div><dt>Tax setting</dt><dd>{{ $branch->tax_rate }}%</dd></div></dl><p class="muted">{{ $branch->timezone }}</p><a class="btn btn-outline btn-block" href="{{ route('branches.edit', $branch) }}">Manage settings <span aria-hidden="true">↗</span></a></article>
@empty<div class="panel empty-state"><span class="empty-symbol">⌂</span><h2>Every business starts somewhere.</h2><p>Create your first branch to configure settings and assign staff.</p><a class="btn btn-primary" href="{{ route('branches.create') }}">Create your first branch →</a></div>@endforelse</div>
<div class="pagination-area">{{ $branches->links() }}</div>
<div class="info-strip"><strong>Branch access stays separate.</strong> Managers and cashiers see only their assigned active branches. Administrators manage every location.</div>
@endsection
