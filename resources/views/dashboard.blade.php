@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<h1 style="font-size:1.5rem;font-weight:700;margin-bottom:1.25rem;color:#1e293b;">📊 Dashboard</h1>

<div class="stat-grid">
    <div class="stat-card" style="border-color:#6366f1">
        <div class="stat-label">Total Requests</div>
        <div class="stat-value" style="color:#6366f1">{{ $totalRequests }}</div>
    </div>
    <div class="stat-card" style="border-color:#10b981">
        <div class="stat-label">Issued</div>
        <div class="stat-value" style="color:#10b981">{{ $statusCounts['ISSUED'] ?? 0 }}</div>
    </div>
    <div class="stat-card" style="border-color:#f59e0b">
        <div class="stat-label">Pending</div>
        <div class="stat-value" style="color:#f59e0b">{{ $statusCounts['PENDING_CONFIRMATION'] ?? 0 }}</div>
    </div>
    <div class="stat-card" style="border-color:#ef4444">
        <div class="stat-label">Failed</div>
        <div class="stat-value" style="color:#ef4444">{{ $statusCounts['FAILED'] ?? 0 }}</div>
    </div>
    <div class="stat-card" style="border-color:#ec4899">
        <div class="stat-label">Reconciliation</div>
        <div class="stat-value" style="color:#ec4899">{{ $statusCounts['NEEDS_RECONCILIATION'] ?? 0 }}</div>
    </div>
    <div class="stat-card" style="border-color:#0ea5e9">
        <div class="stat-label">Received</div>
        <div class="stat-value" style="color:#0ea5e9">{{ $statusCounts['RECEIVED'] ?? 0 }}</div>
    </div>
    <div class="stat-card" style="border-color:#8b5cf6">
        <div class="stat-label">Submitted</div>
        <div class="stat-value" style="color:#8b5cf6">{{ ($statusCounts['SUBMITTED'] ?? 0) + ($statusCounts['VALIDATED'] ?? 0) }}</div>
    </div>
</div>

<div class="card">
    <h2>Status Breakdown</h2>
    <table>
        <thead><tr><th>Status</th><th>Count</th><th>Action</th></tr></thead>
        <tbody>
        @foreach($statusCounts as $status => $count)
        <tr>
            <td><span class="badge badge-{{ strtolower($status) }}">{{ $status }}</span></td>
            <td><strong>{{ $count }}</strong></td>
            <td><a href="{{ route('requests.index', ['status' => $status]) }}" class="btn btn-sm btn-primary">View</a></td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Quick Actions</h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
        <a href="{{ route('requests.create') }}" class="btn btn-primary">➕ New Policy Request</a>
        <a href="{{ route('requests.index') }}" class="btn btn-success">📋 View All Requests</a>
    </div>
</div>
@endsection
