@extends('layouts.app')
@section('title', 'Request ' . substr($policyRequest->request_uuid, 0, 8))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h1 style="font-size:1.5rem;font-weight:700;color:#1e293b;">📄 Policy Request Detail</h1>
    <a href="{{ route('requests.index') }}" class="btn" style="background:#e2e8f0;color:#334155;">← Back</a>
</div>

<div id="action-result"></div>

<div class="grid-2" style="gap:1.5rem;">
<div>
<div class="card">
    <h2>Request Information</h2>
    <table style="font-size:0.875rem;">
        <tbody>
        <tr><td style="color:#64748b;width:40%">Request UUID</td><td style="font-family:monospace;font-size:0.75rem;word-break:break-all">{{ $policyRequest->request_uuid }}</td></tr>
        <tr><td style="color:#64748b">Status</td><td><span class="badge badge-{{ strtolower($policyRequest->status) }}" id="status-badge">{{ $policyRequest->status }}</span></td></tr>
        <tr><td style="color:#64748b">Agent ID</td><td>{{ $policyRequest->agent_id }}</td></tr>
        <tr><td style="color:#64748b">Client Request ID</td><td>{{ $policyRequest->client_request_id }}</td></tr>
        <tr><td style="color:#64748b">Customer Name</td><td>{{ $policyRequest->customer_name }}</td></tr>
        <tr><td style="color:#64748b">Date of Birth</td><td>{{ $policyRequest->date_of_birth?->format('Y-m-d') }}</td></tr>
        <tr><td style="color:#64748b">Age at Trip Start</td><td>{{ $policyRequest->customer_age }} years</td></tr>
        <tr><td style="color:#64748b">Destination</td><td>{{ $policyRequest->destination }}</td></tr>
        <tr><td style="color:#64748b">Trip Period</td><td>{{ $policyRequest->trip_start?->format('Y-m-d') }} → {{ $policyRequest->trip_end?->format('Y-m-d') }} ({{ $policyRequest->travel_days }} days)</td></tr>
        <tr><td style="color:#64748b">Plan Code</td><td>{{ $policyRequest->plan_code }}</td></tr>
        <tr><td style="color:#64748b">Sum Insured</td><td>₹{{ number_format($policyRequest->sum_insured) }}</td></tr>
        <tr><td style="color:#64748b">Premium</td><td><strong style="font-size:1.1rem;color:#15803d">₹{{ number_format($policyRequest->premium, 2) }}</strong></td></tr>
        <tr><td style="color:#64748b">Policy Number</td><td><strong>{{ $policyRequest->policy_number ?? '—' }}</strong></td></tr>
        <tr><td style="color:#64748b">Provider Reference</td><td>{{ $policyRequest->provider_reference ?? '—' }}</td></tr>
        <tr><td style="color:#64748b">Attempts</td><td>{{ $policyRequest->attempt_count }}</td></tr>
        <tr><td style="color:#64748b">Created</td><td>{{ $policyRequest->created_at?->format('Y-m-d H:i:s') }}</td></tr>
        </tbody>
    </table>
</div>

<!-- Submit / Retry Actions -->
@if(!in_array($policyRequest->status, ['ISSUED']))
<div class="card">
    <h2>Actions</h2>
    <div class="form-group">
        <label>Mock Scenario</label>
        <select id="mock-scenario">
            <option value="SUCCESS">SUCCESS</option>
            <option value="TEMPORARY_FAILURE">TEMPORARY_FAILURE</option>
            <option value="TIMEOUT_AFTER_ISSUE">TIMEOUT_AFTER_ISSUE</option>
            <option value="DELAYED_CALLBACK">DELAYED_CALLBACK</option>
            <option value="DUPLICATE_CALLBACK">DUPLICATE_CALLBACK</option>
        </select>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="submitRequest()">▶ Submit/Retry</button>
        @if($policyRequest->status === 'NEEDS_RECONCILIATION')
        <button class="btn btn-warning" onclick="reconcileRequest()">🔄 Reconcile</button>
        @endif
    </div>
</div>
@endif

@if($policyRequest->status === 'PENDING_CONFIRMATION' && $policyRequest->provider_reference)
<div class="card">
    <h2>Send Callback</h2>
    <p style="font-size:0.875rem;margin-bottom:1rem;color:#64748b;">Simulate a provider callback for this request.</p>
    <div class="form-group">
        <label>Event Type</label>
        <select id="cb-event-type">
            <option value="ISSUED">ISSUED</option>
            <option value="FAILED">FAILED</option>
        </select>
    </div>
    <button class="btn btn-success" onclick="sendCallback()">📩 Send Callback</button>
</div>
@endif
</div>

<div>
<!-- State History -->
<div class="card">
    <h2>State History</h2>
    @forelse($policyRequest->stateHistory as $h)
    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;font-size:0.85rem;">
        <span class="badge badge-{{ strtolower($h->from_state ?? 'received') }}">{{ $h->from_state ?? 'START' }}</span>
        <span>→</span>
        <span class="badge badge-{{ strtolower($h->to_state) }}">{{ $h->to_state }}</span>
        <span class="text-muted">{{ $h->transitioned_at?->format('H:i:s') }}</span>
        <span style="color:#475569">{{ $h->reason }}</span>
    </div>
    @empty
    <p class="text-muted">No transitions yet.</p>
    @endforelse
</div>

<!-- Issuance Attempts -->
<div class="card">
    <h2>Issuance Attempts ({{ $policyRequest->issuanceAttempts->count() }})</h2>
    @forelse($policyRequest->issuanceAttempts as $a)
    <div style="border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem;margin-bottom:0.75rem;font-size:0.85rem;">
        <div style="display:flex;justify-content:space-between;">
            <strong>Attempt #{{ $a->attempt_number }}</strong>
            <span class="badge badge-{{ strtolower($a->status) }}">{{ $a->status }}</span>
        </div>
        @if($a->policy_number)<div>Policy: <strong>{{ $a->policy_number }}</strong></div>@endif
        @if($a->error_message)<div style="color:#dc2626">{{ $a->error_message }}</div>@endif
        <div class="text-muted">{{ $a->attempted_at?->format('Y-m-d H:i:s') }}</div>
    </div>
    @empty
    <p class="text-muted">No attempts yet.</p>
    @endforelse
</div>

<!-- Provider Events -->
@if($policyRequest->providerEvents->count() > 0)
<div class="card">
    <h2>Provider Events</h2>
    @foreach($policyRequest->providerEvents as $e)
    <div style="border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem;margin-bottom:0.5rem;font-size:0.85rem;">
        <div style="display:flex;justify-content:space-between;">
            <strong>{{ $e->event_type }}</strong>
            <span style="color:{{ $e->processed ? '#15803d' : '#d97706' }}">{{ $e->processed ? '✅ Processed' : '⏳ Pending' }}</span>
        </div>
        @if($e->policy_number)<div>Policy: {{ $e->policy_number }}</div>@endif
        <div class="text-muted" style="font-size:0.75rem;font-family:monospace">{{ $e->provider_event_id }}</div>
    </div>
    @endforeach
</div>
@endif
</div>
</div>
@endsection

@section('scripts')
<script>
const requestId = '{{ $policyRequest->request_uuid }}';
const providerRef = '{{ $policyRequest->provider_reference }}';

async function submitRequest() {
    const scenario = document.getElementById('mock-scenario').value;
    const res = await apiCall('POST', `/api/policy-requests/${requestId}/submit`, { mock_scenario: scenario });
    showResult(res);
    if (res.ok) setTimeout(() => location.reload(), 1500);
}

async function reconcileRequest() {
    const res = await apiCall('POST', `/api/policy-requests/${requestId}/reconcile`);
    showResult(res);
    if (res.ok) setTimeout(() => location.reload(), 1500);
}

async function sendCallback() {
    const eventType = document.getElementById('cb-event-type').value;
    const policyNum = eventType === 'ISSUED' ? 'POL-CALLBACK-' + Date.now() : null;
    const body = {
        provider_event_id: 'EVT-UI-' + Date.now(),
        provider_reference: providerRef,
        event_type: eventType,
        policy_number: policyNum,
    };
    const res = await apiCall('POST', '/api/mock-insurer/callback', body);
    showResult(res);
    if (res.ok) setTimeout(() => location.reload(), 1500);
}

function showResult(res) {
    const el = document.getElementById('action-result');
    const cls = res.ok ? 'alert-success' : 'alert-error';
    const msg = res.data.message || (res.ok ? 'Success' : 'Error');
    el.innerHTML = `<div class="alert ${cls}">${msg}</div>`;
}
</script>
@endsection
