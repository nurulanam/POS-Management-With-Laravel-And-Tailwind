@extends('layouts.management')
@section('title', 'Suppliers')
@section('content')
<div class="page-heading"><div><span class="eyebrow">{{ $currentBranch->name }} · SUPPLY NETWORK</span><h1>The people behind your stock.</h1><p>Branch-local supplier contacts. Archive instead of deleting; original purchase orders retain their snapshots.</p></div><a class="btn btn-primary" href="{{ route('suppliers.create') }}">+ Add supplier</a></div>
<section class="panel table-panel"><form class="filter-bar" method="GET"><div class="field search-field"><label for="q">Find a supplier</label><input id="q" name="q" maxlength="100" value="{{ request('q') }}" placeholder="Name or supplier code"></div><div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="archived" @selected(request('status') === 'archived')>Archived</option></select></div><button class="btn btn-dark">Filter</button><a class="text-link" href="{{ route('suppliers.index') }}">Clear</a></form>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Supplier</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($suppliers as $supplier)
<tr><td>{{ $supplier->name }}<small class="table-note">{{ $supplier->code }}</small></td><td>{{ $supplier->contact_name }}<small class="table-note">{{ $supplier->email }} {{ $supplier->phone }}</small></td><td><span class="badge {{ $supplier->is_active ? 'badge-positive' : 'badge-neutral' }}">{{ $supplier->is_active ? 'Active' : 'Archived' }}</span></td><td><a class="text-link" href="{{ route('suppliers.edit', $supplier) }}">Edit</a></td></tr>
@empty
<tr><td colspan="4"><div class="empty-state"><h2>No suppliers found</h2><p>Add a supplier before placing a purchase order.</p></div></td></tr>
@endforelse
</tbody></table></div><div class="pagination-area">{{ $suppliers->links() }}</div></section>
@endsection
