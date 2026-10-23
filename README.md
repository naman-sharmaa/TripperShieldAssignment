# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is an enterprise-grade, fault-tolerant travel insurance issuance and reconciliation backend built with **Laravel 11**, **PHP 8.2+**, and **MySQL 8+**. It guarantees strict idempotency, prevents duplicate issuance under network timeouts, handles asynchronous callbacks, and provides automated reconciliation for stale policies.

---

## 🚀 Key Features & Capabilities

1. **Deterministic State Machine & Concurrency Control**
   - States: `RECEIVED` → `VALIDATED` → `SUBMITTED` → `PENDING_CONFIRMATION` | `ISSUED` | `FAILED` | `NEEDS_RECONCILIATION`
   - Terminal state enforcement: Once `ISSUED`, a policy is immutable and cannot be overwritten.
   - Concurrency locking with `lockForUpdate()` prevents race conditions across worker processes.

2. **Strict Multi-Layer Idempotency**
   - **Intake Layer**: Unique database composite constraint on `(agent_id, client_request_id)`. Re-submitting the same payload returns the exact existing record with `is_duplicate: true`.
   - **Submission Layer**: Unique per-attempt idempotency keys (`{request_uuid}-attempt-{attempt_number}`).
   - **Callback Layer**: Unique `provider_event_id` constraint prevents duplicate processing of mock insurer webhooks.

3. **Deterministic Premium Calculation**
   - Formula: `Premium = Base Rate × Travel Days × Sum Insured Factor × Age Multiplier`
   - Plans:
     - `BASIC`: Base $2.00/day
     - `STANDARD`: Base $3.50/day
     - `PREMIUM`: Base $5.00/day
   - Destination Surcharge: Domestic (1.0x), International (1.5x)
   - Sum Insured Factors: $100k (1.0x), $300k (1.5x), $500k (2.0x)
   - Age Multipliers calculated on trip start date:
     - 0–17: 0.8x
     - 18–65: 1.0x
     - 66–75: 1.5x
     - 76+: 2.0x

4. **Fault Tolerance & Deterministic Mock Insurer**
   - Supported test scenarios:
     - `SUCCESS`: Immediate synchronous issuance with a unique policy number.
     - `TEMPORARY_FAILURE`: Fails on initial attempts with transient errors, succeeds on retry.
     - `TIMEOUT_AFTER_ISSUE`: Simulates network drops where the provider issued the policy but the client received a timeout. Request transitions to `NEEDS_RECONCILIATION`.
     - `DELAYED_CALLBACK`: Enters `PENDING_CONFIRMATION`, waiting for asynchronous webhook.
     - `DUPLICATE_CALLBACK`: Verifies webhook processing idempotency.

5. **Reconciliation Engine**
   - Detects stale policies in `NEEDS_RECONCILIATION`, `PENDING_CONFIRMATION`, or `SUBMITTED`.
   - Polls provider status and recovers policies without issuing duplicates.
   - CLI command: `php artisan policy:reconcile` (scheduled every 5 minutes in `routes/console.php`).

6. **Interactive Blade UI**
   - **Dashboard**: Real-time KPI cards with status counts.
   - **Policy Requests Explorer**: Filter by status, view paginated requests.
   - **Request Intake Form**: Dynamic premium calculation preview and instant API submission.
   - **Policy Request Details**: Audit timeline of state transitions and issuance attempts with manual retry and reconciliation buttons.

---

## 🛠️ Project Setup & Installation

### Requirements
- PHP 8.2+ with `pdo_mysql`, `bcmath`, `curl`, `mbstring`, `openssl`
- MySQL 8.0+
- Composer 2+

### Configuration
In `policyflow/.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=system
```

### Run Migrations
```bash
cd policyflow
php artisan migrate
```

### Run Dev Server
```bash
php artisan serve
```
Open your browser at: `http://localhost:8000`

---

## 🧪 Automated Test Suite

PolicyFlow includes a comprehensive automated test suite covering all business rules, edge cases, concurrent submissions, timeout recovery, and web routes.

```bash
cd policyflow
php artisan test
```

### Test Coverage Highlights (35 Tests / 89 Assertions)
- `test_valid_request_creates_policy_with_correct_premium`
- `test_invalid_trip_dates_rejected`
- `test_unsupported_plan_rejected`
- `test_unsupported_coverage_rejected`
- `test_duplicate_intake_returns_same_logical_request`
- `test_different_agent_same_client_request_id_allowed`
- `test_premium_unchanged_during_retry`
- `test_successful_issuance_produces_exactly_one_policy_number`
- `test_issued_state_cannot_be_overwritten`
- `test_timeout_after_issue_moves_to_needs_reconciliation`
- `test_timeout_followed_by_reconciliation_recovers_policy_without_duplicate`
- `test_duplicate_callbacks_do_not_duplicate_processing`
- `test_temporary_failure_allows_retry`
- `test_artisan_reconcile_command_reconciles_requests`
- `test_web_routes_render_successfully`
- Full `PremiumCalculatorTest` unit test suite for all boundary conditions.

---

## 📡 API Reference

### 1. Create Policy Request
`POST /api/policy-requests`

**Request Body:**
```json
{
  "agent_id": "AGT-001",
  "client_request_id": "REQ-1001",
  "customer_name": "Jane Doe",
  "date_of_birth": "1990-05-15",
  "destination": "international",
  "trip_start": "2026-12-01",
  "trip_end": "2026-12-10",
  "plan_code": "PREMIUM",
  "sum_insured": 300000
}
```

**Response (201 Created / 200 OK for Duplicate):**
```json
{
  "success": true,
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "agent_id": "AGT-001",
    "client_request_id": "REQ-1001",
    "customer_name": "Jane Doe",
    "status": "RECEIVED",
    "travel_days": 10,
    "customer_age": 36,
    "premium": 112.50
  },
  "is_duplicate": false
}
```

### 2. Submit to Insurer
`POST /api/policy-requests/{id}/submit`

**Request Body:**
```json
{
  "mock_scenario": "SUCCESS"
}
```
*(Options: `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, `DUPLICATE_CALLBACK`)*

### 3. Reconcile Request
`POST /api/policy-requests/{id}/reconcile`

### 4. Mock Insurer Webhook Callback
`POST /api/mock-insurer/callback`

```json
{
  "provider_event_id": "EVT-CALLBACK-001",
  "provider_reference": "MOCK-ABC12345",
  "event_type": "ISSUED",
  "policy_number": "POL-2026-A1B2C3D4E5"
}
```

### 5. Reconciliation CLI Command
```bash
# Reconcile all stale or pending requests
php artisan policy:reconcile --minutes=5

# Reconcile specific request UUID
php artisan policy:reconcile --id=550e8400-e29b-41d4-a716-446655440000
```
