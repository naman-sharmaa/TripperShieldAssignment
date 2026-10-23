@extends('layouts.app')
@section('title', 'New Policy Request')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h1 style="font-size:1.5rem;font-weight:700;color:#1e293b;">➕ New Policy Request</h1>
    <a href="{{ route('requests.index') }}" class="btn" style="background:#e2e8f0;color:#334155;">← Back</a>
</div>

<div id="alert-container"></div>

<div class="card">
    <h2>Request Details</h2>
    <form id="policy-form">
        <div class="grid-2">
            <div class="form-group">
                <label>Agent ID *</label>
                <input type="text" id="agent_id" name="agent_id" placeholder="e.g. AGENT-001" required>
                <div class="error-text" id="err-agent_id"></div>
            </div>
            <div class="form-group">
                <label>Client Request ID *</label>
                <input type="text" id="client_request_id" name="client_request_id" placeholder="Unique ID from your system" required>
                <div class="error-text" id="err-client_request_id"></div>
            </div>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label>Customer Name *</label>
                <input type="text" id="customer_name" name="customer_name" placeholder="Full name" required>
                <div class="error-text" id="err-customer_name"></div>
            </div>
            <div class="form-group">
                <label>Date of Birth *</label>
                <input type="date" id="date_of_birth" name="date_of_birth" required>
                <div class="error-text" id="err-date_of_birth"></div>
            </div>
        </div>
        <div class="grid-3">
            <div class="form-group">
                <label>Destination *</label>
                <select id="destination" name="destination" required>
                    <option value="">Select...</option>
                    <option value="DOMESTIC">DOMESTIC</option>
                    <option value="INTERNATIONAL">INTERNATIONAL</option>
                </select>
                <div class="error-text" id="err-destination"></div>
            </div>
            <div class="form-group">
                <label>Plan Code *</label>
                <select id="plan_code" name="plan_code" required>
                    <option value="">Select...</option>
                    <option value="BASIC">BASIC (₹12/day)</option>
                    <option value="PLUS">PLUS (₹20/day)</option>
                    <option value="PREMIUM">PREMIUM (₹30/day)</option>
                </select>
                <div class="error-text" id="err-plan_code"></div>
            </div>
            <div class="form-group">
                <label>Sum Insured *</label>
                <select id="sum_insured" name="sum_insured" required>
                    <option value="">Select...</option>
                    <option value="100000">₹1,00,000</option>
                    <option value="300000">₹3,00,000</option>
                    <option value="500000">₹5,00,000</option>
                </select>
                <div class="error-text" id="err-sum_insured"></div>
            </div>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label>Trip Start *</label>
                <input type="date" id="trip_start" name="trip_start" required>
                <div class="error-text" id="err-trip_start"></div>
            </div>
            <div class="form-group">
                <label>Trip End *</label>
                <input type="date" id="trip_end" name="trip_end" required>
                <div class="error-text" id="err-trip_end"></div>
            </div>
        </div>

        <div id="premium-preview" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:1rem;margin-bottom:1rem;">
            <strong>📊 Estimated Premium:</strong>
            <span id="premium-value" style="font-size:1.4rem;font-weight:700;color:#15803d;margin-left:0.5rem;"></span>
            <span class="text-muted" style="font-size:0.8rem;margin-left:0.5rem;" id="premium-details"></span>
        </div>

        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-success" onclick="previewPremium()">💰 Preview Premium</button>
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
// Simple client-side premium preview (mirrors server calculation)
const BASE_RATES = { BASIC: 12, PLUS: 20, PREMIUM: 30 };
const AGE_MULT   = (age) => age <= 17 ? 1.10 : age <= 60 ? 1.00 : age <= 70 ? 1.50 : 2.00;
const DEST_MULT  = { DOMESTIC: 1.00, INTERNATIONAL: 1.80 };
const COV_MULT   = { 100000: 1.00, 300000: 1.25, 500000: 1.50 };

function calcAge(dob, ref) {
    const d = new Date(dob), r = new Date(ref);
    let age = r.getFullYear() - d.getFullYear();
    if (r.getMonth() < d.getMonth() || (r.getMonth() === d.getMonth() && r.getDate() < d.getDate())) age--;
    return age;
}

function daysDiff(start, end) {
    return Math.round((new Date(end) - new Date(start)) / 86400000) + 1;
}

function previewPremium() {
    const plan = document.getElementById('plan_code').value;
    const dob  = document.getElementById('date_of_birth').value;
    const start = document.getElementById('trip_start').value;
    const end   = document.getElementById('trip_end').value;
    const dest  = document.getElementById('destination').value;
    const cov   = document.getElementById('sum_insured').value;

    if (!plan || !dob || !start || !end || !dest || !cov) {
        alert('Please fill all fields to preview premium.'); return;
    }

    const age   = calcAge(dob, start);
    const days  = daysDiff(start, end);
    const prem  = BASE_RATES[plan] * days * AGE_MULT(age) * DEST_MULT[dest] * COV_MULT[cov];

    document.getElementById('premium-value').textContent = '₹' + prem.toFixed(2);
    document.getElementById('premium-details').textContent = `(${days} days, age ${age})`;
    document.getElementById('premium-preview').style.display = 'block';
}

document.getElementById('policy-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    // Clear errors
    document.querySelectorAll('.error-text').forEach(el => el.textContent = '');
    document.getElementById('alert-container').innerHTML = '';

    const body = {
        agent_id:          document.getElementById('agent_id').value,
        client_request_id: document.getElementById('client_request_id').value,
        customer_name:     document.getElementById('customer_name').value,
        date_of_birth:     document.getElementById('date_of_birth').value,
        destination:       document.getElementById('destination').value,
        trip_start:        document.getElementById('trip_start').value,
        trip_end:          document.getElementById('trip_end').value,
        plan_code:         document.getElementById('plan_code').value,
        sum_insured:       parseInt(document.getElementById('sum_insured').value),
    };

    const submitBtn = e.target.querySelector('[type=submit]');
    submitBtn.disabled = true; submitBtn.textContent = 'Submitting…';

    try {
        const res = await apiCall('POST', '/api/policy-requests', body);
        submitBtn.disabled = false; submitBtn.textContent = 'Submit Request';

        if (res.ok) {
            const msg = res.data.created ? 'Policy request created!' : 'Existing request returned (idempotent).';
            document.getElementById('alert-container').innerHTML =
                `<div class="alert alert-success">${msg} <a href="/requests/${res.data.data.id}">View Request →</a></div>`;
        } else if (res.status === 422) {
            const errs = res.data.errors || {};
            Object.entries(errs).forEach(([field, msgs]) => {
                const el = document.getElementById(`err-${field}`);
                if (el) el.textContent = msgs[0];
            });
            document.getElementById('alert-container').innerHTML =
                `<div class="alert alert-error">${res.data.message}</div>`;
        } else {
            document.getElementById('alert-container').innerHTML =
                `<div class="alert alert-error">${res.data.message || 'An error occurred.'}</div>`;
        }
    } catch(err) {
        submitBtn.disabled = false; submitBtn.textContent = 'Submit Request';
        document.getElementById('alert-container').innerHTML =
            `<div class="alert alert-error">Network error: ${err.message}</div>`;
    }
});
</script>
@endsection
