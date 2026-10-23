@extends('layouts.app')
@section('title', 'Policy Requests')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h1 style="font-size:1.5rem;font-weight:700;color:#1e293b;">📋 Policy Requests</h1>
    <a href="{{ route('requests.create') }}" class="btn btn-primary">➕ New Request</a>
</div>

<div class="card" style="padding:1rem;">
    <form method="GET" action="{{ route('requests.index') }}" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;flex:1;min-width:180px;">
            <label>Filter by Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                @foreach($allStatuses as $status)
                <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ $status }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        @if(request('status'))
        <a href="{{ route('requests.index') }}" class="btn" style="background:#e2e8f0;color:#334155;">Clear</a>
        @endif
    </form>
</div>

<div class="card" style="padding:0;overflow:auto;">
    <table>
        <thead>
            <tr>
                <th>Request ID</th>
                <th>Customer</th>
                <th>Plan</th>
                <th>Premium</th>
                <th>Status</th>
                <th>Policy #</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse($requests as $r)
        <tr>
            <td style="font-family:monospace;font-size:0.75rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $r->request_uuid }}">
                {{ substr($r->request_uuid, 0, 8) }}…
            </td>
            <td>{{ $r->customer_name }}</td>
            <td>{{ $r->plan_code }}</td>
            <td><strong>₹{{ number_format($r->premium, 2) }}</strong></td>
            <td><span class="badge badge-{{ strtolower($r->status) }}">{{ $r->status }}</span></td>
            <td>{{ $r->policy_number ?? '-' }}</td>
            <td class="text-muted" style="font-size:0.8rem;">{{ $r->created_at->format('Y-m-d H:i') }}</td>
            <td>
                <a href="{{ route('requests.show', $r->request_uuid) }}" class="btn btn-sm btn-primary">View</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">No policy requests found.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="padding:1rem;">
        {{ $requests->links('pagination::simple-bootstrap-4') }}
    </div>
</div>
@endsection
