<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Overview') · Sunset Sales</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="management">
<div class="workspace">
    <aside class="workspace-sidebar">
        <a class="workspace-brand" href="{{ route('dashboard') }}"><span class="brand-mark">S</span><span>sunset<span class="brand-light">sales</span><small>BUSINESS WORKSPACE</small></span></a>
        <div class="nav-label">WORKSPACE</div>
        <nav class="workspace-nav" aria-label="Main navigation">
            <a @class(['selected' => request()->routeIs('dashboard')]) href="{{ route('dashboard') }}"><span aria-hidden="true">◫</span> Overview</a>
            @can('manage-business')
                <a @class(['selected' => request()->routeIs('users.*')]) href="{{ route('users.index') }}"><span aria-hidden="true">♙</span> User management</a>
                <a @class(['selected' => request()->routeIs('branches.*')]) href="{{ route('branches.index') }}"><span aria-hidden="true">⚙</span> Branches & settings</a>
            @endcan
            @can('view-team')
                <a @class(['selected' => request()->routeIs('team.*')]) href="{{ route('team.index') }}"><span aria-hidden="true">☷</span> Branch team</a>
            @endcan
            <a @class(['selected' => request()->routeIs('password.*')]) href="{{ route('password.edit') }}"><span aria-hidden="true">⌘</span> Account security</a>
        </nav>
        <div class="sidebar-note"><span class="status-dot"></span> Built for your everyday.<p>One workspace.<br>Every branch connected.</p></div>
        <div class="sidebar-profile"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role->label() }}</small></span></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="signout" type="submit">Sign out <span aria-hidden="true">↗</span></button></form>
    </aside>
    <div class="workspace-main">
        <header class="workspace-topbar">
            <div class="breadcrumb">Workspace <span>/</span> <strong>@yield('title', 'Overview')</strong></div>
            @if(isset($availableBranches) && $availableBranches->isNotEmpty())
                <form class="branch-switch" method="POST" action="{{ route('branch.switch') }}">
                    @csrf
                    <label for="workspace-branch">Active branch</label>
                    <select name="branch_id" id="workspace-branch" aria-label="Active branch">
                        @foreach($availableBranches as $option)
                            <option value="{{ $option->id }}" @selected($currentBranch?->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-small btn-outline">Switch</button>
                </form>
            @else
                <span class="badge badge-neutral">{{ auth()->user()->role->label() }} workspace</span>
            @endif
        </header>
        <main id="main-content" class="workspace-content">
            @if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())
                <div class="notice notice-error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
            <footer class="workspace-footer"><span>Sunset Sales · Your business, in focus.</span><span>User management & branch settings</span></footer>
        </main>
    </div>
</div>
</body>
</html>
