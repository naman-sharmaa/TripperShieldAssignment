# PolicyFlow Sample API Requests & cURL Commands

Base URL: `http://localhost:8000`

---

## 1. Create a New Policy Request
```bash
curl -X POST http://localhost:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```
*Expected Response (201 Created):*
```json
{
  "success": true,
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "status": "RECEIVED",
    "travel_days": 5,
    "customer_age": 28,
    "premium": "225.00"
  },
  "is_duplicate": false
}
```

---

## 2. Duplicate Intake Request (Idempotency)
Re-running the exact same request above returns HTTP 200 with the existing record and `"is_duplicate": true`.

---

## 3. Retrieve Request Details & Audit Trail
```bash
curl -X GET http://localhost:8000/api/policy-requests/550e8400-e29b-41d4-a716-446655440000 \
  -H "Accept: application/json"
```

---

## 4. Submit to Insurer (Immediate Success)
```bash
curl -X POST http://localhost:8000/api/policy-requests/550e8400-e29b-41d4-a716-446655440000/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "mock_scenario": "SUCCESS"
  }'
```

---

## 5. Submit with Timeout (Fault Simulation)
```bash
curl -X POST http://localhost:8000/api/policy-requests/550e8400-e29b-41d4-a716-446655440000/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "mock_scenario": "TIMEOUT_AFTER_ISSUE"
  }'
```

---

## 6. Reconcile Policy Request
```bash
curl -X POST http://localhost:8000/api/policy-requests/550e8400-e29b-41d4-a716-446655440000/reconcile \
  -H "Accept: application/json"
```

---

## 7. Webhook Callback from Mock Insurer
```bash
curl -X POST http://localhost:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "EVT-CALLBACK-001",
    "provider_reference": "MOCK-ABC12345",
    "event_type": "ISSUED",
    "policy_number": "POL-2026-A1B2C3D4E5"
  }'
```

---

## 8. List Requests with Filter & Pagination
```bash
curl -X GET "http://localhost:8000/api/policy-requests?status=ISSUED&per_page=15" \
  -H "Accept: application/json"
```
