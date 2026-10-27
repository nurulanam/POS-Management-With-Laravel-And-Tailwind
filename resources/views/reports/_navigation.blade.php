<nav class="report-navigation no-print" aria-label="Report sections">
    <a class="btn {{ request()->routeIs('reports.sales') ? 'btn-dark' : 'btn-outline' }}" href="{{ route('reports.sales') }}" @if(request()->routeIs('reports.sales')) aria-current="page" @endif>Sales activity</a>
    <a class="btn {{ request()->routeIs('reports.inventory') ? 'btn-dark' : 'btn-outline' }}" href="{{ route('reports.inventory') }}" @if(request()->routeIs('reports.inventory')) aria-current="page" @endif>Stock movements</a>
    <a class="btn {{ request()->routeIs('reports.low-stock') ? 'btn-dark' : 'btn-outline' }}" href="{{ route('reports.low-stock') }}" @if(request()->routeIs('reports.low-stock')) aria-current="page" @endif>Current low stock</a>
</nav>
