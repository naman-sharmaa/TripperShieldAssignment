<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PolicyFlow') — PolicyFlow</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; }
        .navbar { background: #1e293b; color: white; padding: 0 1.5rem; display: flex; align-items: center; height: 56px; gap: 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .navbar .brand { font-size: 1.25rem; font-weight: 700; color: #38bdf8; text-decoration: none; }
        .navbar a { color: #cbd5e1; text-decoration: none; font-size: 0.9rem; padding: 0.25rem 0.5rem; border-radius: 4px; transition: background 0.15s; }
        .navbar a:hover, .navbar a.active { background: #334155; color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #334155; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; }
        .btn { display: inline-block; padding: 0.5rem 1.25rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; border: none; cursor: pointer; text-decoration: none; transition: all 0.15s; }
        .btn-primary { background: #0ea5e9; color: white; } .btn-primary:hover { background: #0284c7; }
        .btn-success { background: #10b981; color: white; } .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: white; } .btn-warning:hover { background: #d97706; }
        .btn-danger  { background: #ef4444; color: white; } .btn-danger:hover  { background: #dc2626; }
        .btn-sm { padding: 0.25rem 0.75rem; font-size: 0.8rem; }
        .badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .badge-received { background: #dbeafe; color: #1d4ed8; }
        .badge-validated { background: #e0e7ff; color: #4338ca; }
        .badge-submitted { background: #fef3c7; color: #92400e; }
        .badge-pending_confirmation { background: #fde68a; color: #78350f; }
        .badge-issued { background: #d1fae5; color: #065f46; }
        .badge-failed { background: #fee2e2; color: #991b1b; }
        .badge-needs_reconciliation { background: #fce7f3; color: #9d174d; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-info    { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { background: #f8fafc; text-align: left; padding: 0.75rem 1rem; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; }
        tr:hover td { background: #f8fafc; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.3rem; }
        input, select { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: white; }
        input:focus, select:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: white; border-radius: 8px; padding: 1.25rem 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,0.08); border-left: 4px solid; }
        .stat-card .stat-label { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 0.5rem; }
        .stat-card .stat-value { font-size: 2rem; font-weight: 700; }
        .error-text { color: #dc2626; font-size: 0.8rem; margin-top: 0.25rem; }
        .pagination { display: flex; gap: 0.25rem; margin-top: 1rem; justify-content: center; }
        .pagination a, .pagination span { padding: 0.35rem 0.75rem; border-radius: 4px; font-size: 0.875rem; text-decoration: none; }
        .pagination a { color: #0ea5e9; } .pagination a:hover { background: #e0f2fe; }
        .pagination .current { background: #0ea5e9; color: white; }
        .text-muted { color: #94a3b8; }
        pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 6px; overflow: auto; font-size: 0.8rem; white-space: pre-wrap; }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="{{ route('dashboard') }}" class="brand">🛡 PolicyFlow</a>
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
    <a href="{{ route('requests.index') }}" class="{{ request()->routeIs('requests.*') ? 'active' : '' }}">Policy Requests</a>
    <a href="{{ route('requests.create') }}" class="{{ request()->routeIs('requests.create') ? 'active' : '' }}">New Request</a>
</nav>
<div class="container">
    @yield('content')
</div>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
async function apiCall(method, url, body = null) {
    const opts = { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(url, opts);
    return { ok: res.ok, status: res.status, data: await res.json() };
}
</script>
@yield('scripts')
</body>
</html>
