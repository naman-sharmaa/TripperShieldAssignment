# AI Usage Disclosure & Verification Report

This document satisfies the submission requirements of **Section 10 (AI Usage Policy)** for the TripperShield PolicyFlow Campus Engineering Challenge.

---

## 1. AI Tools Used
- **Antigravity AI Assistant** (DeepMind / Google)
- **Language Models**: Claude 3.7 / Gemini 3.8 / Sonnet

---

## 2. Concrete Ways AI Assisted the Implementation

1. **Deterministic Decimal Arithmetic Implementation**:
   - Designed and implemented [`PremiumCalculator.php`](file:///app/Services/PremiumCalculator.php) using integer-scaled paise arithmetic (`1 INR = 100 paise`) and BCMath to prevent floating-point inaccuracies when calculating age, destination, and sum-insured multipliers.

2. **Multi-Layer Idempotency & Concurrency Design**:
   - Formulated the database-level uniqueness constraints across composite keys (`agent_id`, `client_request_id`) and per-attempt keys (`{request_uuid}-attempt-{attempt_number}`).
   - Embedded pessimistic database locking (`lockForUpdate()`) to prevent race conditions during concurrent state transitions and webhook ingestion.

3. **Comprehensive Automated Test Generation**:
   - Generated unit tests for boundary pricing conditions (e.g. leap years, exact birthdays, inclusive travel days).
   - Generated feature tests covering all four reliability failure scenarios (`Duplicate Intake`, `Timeout After Issue`, `Duplicate Callback`, `Temporary Failure`).

---

## 3. Incorrect or Incomplete AI Suggestions Encountered & How They Were Resolved

1. **Missing Named Parameter in Callback Controller**:
   - *Issue*: An initial suggestion invoked `IssuanceService::processCallback(policyRequest: $policyRequest, ...)` using a named parameter `policyRequest`, but the service parameter was declared as `$request`. In PHP 8.2+, this threw an `Unknown named parameter $policyRequest` fatal error.
   - *Resolution*: Identified via automated test suite execution and refactored the parameter call to match the method signature.

2. **Table Pluralization Discrepancy**:
   - *Issue*: The Eloquent model `PolicyStateHistory` default-resolved to the plural table `policy_state_histories`, whereas the migration created `policy_state_history`.
   - *Resolution*: Added explicit `protected $table = 'policy_state_history';` in the model.

3. **Temporary Failure Retry Threshold**:
   - *Issue*: The retry logic in the mock insurer checked `$attemptCount < 2`, which caused the 2nd attempt to succeed prematurely instead of failing twice as specified by the test scenario.
   - *Resolution*: Updated the conditional to check `$attemptCount < 3`, ensuring Attempt #1 and Attempt #2 both fail and Attempt #3 succeeds.

---

## 4. Verification and Testing Methodology

- **Automated Unit & Feature Tests**:
  - Ran `php artisan test`, executing 35 tests and 89 assertions covering all validation rules, idempotency constraints, state transitions, and UI routes.
- **Database Schema & Foreign Key Verification**:
  - Validated database integrity against MySQL 8 (`SHOW TABLES`, `SHOW CREATE TABLE`), ensuring unique constraints and cascading foreign keys operate at the RDBMS level.
- **Manual End-to-End Simulation**:
  - Verified the complete lifecycle in the interactive Blade dashboard: request intake, premium preview, transient failure retry, timeout recovery via reconciliation CLI, and webhook callbacks.
