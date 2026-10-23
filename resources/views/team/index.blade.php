@extends('layouts.management')
@section('title', 'Branch team')
@section('content')
<div class="page-heading"><div><span class="eyebrow">THE PEOPLE BEHIND THE BUSINESS</span><h1>{{ $branch->name }} team.</h1><p>Active staff assigned to your current branch. Switch branches to view another assigned team.</p></div><span class="badge badge-neutral">{{ $users->total() }} team members</span></div>
<section class="panel table-panel"><div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Staff member</th><th scope="col">Email</th><th scope="col">Role</th></tr></thead><tbody>@forelse($users as $staff)<tr><td><div class="person-cell"><span class="avatar avatar-light">{{ mb_substr($staff->name, 0, 1) }}</span><strong>{{ $staff->name }}</strong></div></td><td>{{ $staff->email }}</td><td><span class="badge badge-role">{{ $staff->role->label() }}</span></td></tr>@empty<tr><td colspan="3"><div class="empty-state"><h2>No active staff assigned</h2><p>An administrator can assign staff using user management.</p></div></td></tr>@endforelse</tbody></table></div><div class="pagination-area">{{ $users->links() }}</div></section>
<div class="info-strip">This is a read-only directory. Contact an administrator to change accounts, permissions, or branch assignments.</div>
@endsection
