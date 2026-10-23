@extends('layouts.management')
@section('title', 'User management')
@section('content')
<div class="page-heading"><div><span class="eyebrow">PEOPLE & PERMISSIONS</span><h1>A place for your whole team.</h1><p>Manage staff accounts, roles, and access across every branch.</p></div><a class="btn btn-primary" href="{{ route('users.create') }}">+ Add staff member</a></div>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Team directory <span class="count-pill">{{ $users->total() }}</span></h2><p class="muted">Administrators have access to all branches.</p></div></div>
    <form class="filter-bar" method="GET" action="{{ route('users.index') }}">
        <div class="field search-field"><label for="q">Search staff</label><input id="q" name="q" type="search" maxlength="100" value="{{ request('q') }}" placeholder="Search by name or email"></div>
        <div class="field"><label for="role">Role</label><select id="role" name="role"><option value="">All roles</option>@foreach($roles as $role)<option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="branch">Branch</label><select id="branch" name="branch"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></div>
        <button class="btn btn-dark" type="submit">Filter</button><a class="text-link" href="{{ route('users.index') }}">Clear</a>
    </form>
    <div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Staff member</th><th scope="col">Role</th><th scope="col">Branch access</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
    @forelse($users as $staff)
        <tr><td><div class="person-cell"><span class="avatar avatar-light">{{ mb_substr($staff->name, 0, 1) }}</span><span><strong>{{ $staff->name }} @if($staff->id === auth()->id())<small class="inline-label">You</small>@endif</strong><small>{{ $staff->email }}</small></span></div></td>
            <td><span class="badge badge-role">{{ $staff->role->label() }}</span></td>
            <td><div class="branch-tags">@if($staff->isAdmin())<span class="muted">All branches</span>@else @forelse($staff->branches as $branch)<span class="badge badge-neutral">{{ $branch->name }}{{ $branch->is_active ? '' : ' (inactive)' }}</span>@empty<span class="muted">Not assigned</span>@endforelse @endif</div></td>
            <td><span @class(['badge', 'badge-positive' => $staff->is_active, 'badge-neutral' => !$staff->is_active])>{{ $staff->is_active ? 'Active' : 'Inactive' }}</span>@if($staff->must_change_password)<small class="table-note">Password change required</small>@endif</td>
            <td><a class="table-edit" href="{{ route('users.edit', $staff) }}" aria-label="Edit {{ $staff->name }}">Edit ↗</a></td></tr>
    @empty<tr><td colspan="5"><div class="empty-state"><h3>No staff match your filters</h3><p>Try another search, or add a staff member to get started.</p><a class="text-link" href="{{ route('users.index') }}">Clear filters</a></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination-area">{{ $users->links() }}</div>
</section>
<div class="info-strip"><strong>Designed to protect your records.</strong> Deactivate staff accounts instead of deleting them. Updating access revokes existing sessions and API tokens.</div>
@endsection
