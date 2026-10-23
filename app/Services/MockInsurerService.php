<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Support\Str;

/**
 * MockInsurerService — local deterministic mock insurer.
 *
 * Scenario selection via the 'mock_scenario' field in the submission request.
 * Supported scenarios:
 *   SUCCESS                - immediate success response
 *   TEMPORARY_FAILURE      - first N attempts fail, then succeed
 *   TIMEOUT_AFTER_ISSUE    - policy is created but response is never returned (timeout)
 *   DELAYED_CALLBACK       - success but with a delayed async callback
 *   DUPLICATE_CALLBACK     - sends two identical callback events
 */
class MockInsurerService
{
    /**
     * Submit a policy request to the mock insurer.
     * Returns a structured array:
     *   status: SUCCESS | FAILED | TIMEOUT
     *   policy_number: string|null
     *   provider_reference: string
     *   error_message: string|null
     */
    public function submit(PolicyRequest $request, string $scenario = 'SUCCESS'): array
    {
        $providerRef = 'MOCK-' . strtoupper(Str::random(8));

        return match ($scenario) {
            'TEMPORARY_FAILURE' => $this->temporaryFailure($request, $providerRef),
            'TIMEOUT_AFTER_ISSUE' => $this->timeoutAfterIssue($request, $providerRef),
            'DELAYED_CALLBACK' => $this->delayedCallback($request, $providerRef),
            'DUPLICATE_CALLBACK' => $this->duplicateCallback($request, $providerRef),
            default => $this->success($request, $providerRef),
        };
    }

    private function success(PolicyRequest $request, string $providerRef): array
    {
        $policyNumber = $this->generatePolicyNumber($request);

        return [
            'status'             => 'SUCCESS',
            'policy_number'      => $policyNumber,
            'provider_reference' => $providerRef,
            'error_message'      => null,
            'scenario'           => 'SUCCESS',
        ];
    }

    private function temporaryFailure(PolicyRequest $request, string $providerRef): array
    {
        // Fail on first two attempts, succeed on third+
        $attemptCount = $request->attempt_count;

        if ($attemptCount < 3) {
            return [
                'status'             => 'FAILED',
                'policy_number'      => null,
                'provider_reference' => $providerRef,
                'error_message'      => 'Temporary provider error. Please retry.',
                'scenario'           => 'TEMPORARY_FAILURE',
            ];
        }

        return $this->success($request, $providerRef);
    }

    private function timeoutAfterIssue(PolicyRequest $request, string $providerRef): array
    {
        // The mock "issues" the policy internally but simulates a network timeout
        // so the application never gets a success response.
        // The policy number IS stored in a provider event for reconciliation.
        $policyNumber = $this->generatePolicyNumber($request);

        // Store the event in provider_events for reconciliation lookup
        ProviderEvent::create([
            'policy_request_id'  => $request->id,
            'issuance_attempt_id'=> null,
            'provider_event_id'  => 'EVT-' . strtoupper(Str::random(12)),
            'event_type'         => 'ISSUED',
            'policy_number'      => $policyNumber,
            'provider_reference' => $providerRef,
            'payload'            => json_encode([
                'scenario'       => 'TIMEOUT_AFTER_ISSUE',
                'policy_number'  => $policyNumber,
                'provider_ref'   => $providerRef,
            ]),
            'processed'          => false,
            'event_timestamp'    => now(),
        ]);

        return [
            'status'             => 'TIMEOUT',
            'policy_number'      => null, // App never receives this
            'provider_reference' => $providerRef,
            'error_message'      => 'Request timed out. Policy may have been issued.',
            'scenario'           => 'TIMEOUT_AFTER_ISSUE',
        ];
    }

    private function delayedCallback(PolicyRequest $request, string $providerRef): array
    {
        // Returns PENDING — callback will arrive later via /api/mock-insurer/callback
        return [
            'status'             => 'PENDING',
            'policy_number'      => null,
            'provider_reference' => $providerRef,
            'error_message'      => null,
            'scenario'           => 'DELAYED_CALLBACK',
        ];
    }

    private function duplicateCallback(PolicyRequest $request, string $providerRef): array
    {
        // Returns PENDING — two callbacks will arrive (duplicate scenario)
        return [
            'status'             => 'PENDING',
            'policy_number'      => null,
            'provider_reference' => $providerRef,
            'error_message'      => null,
            'scenario'           => 'DUPLICATE_CALLBACK',
        ];
    }

    /**
     * Look up provider status for reconciliation.
     * Returns the provider's view of the policy request.
     */
    public function lookupStatus(PolicyRequest $request): array
    {
        // Check if there's a pending provider event (e.g., from TIMEOUT_AFTER_ISSUE)
        $event = ProviderEvent::where('policy_request_id', $request->id)
            ->where('event_type', 'ISSUED')
            ->where('processed', false)
            ->first();

        if ($event) {
            return [
                'found'              => true,
                'status'             => 'ISSUED',
                'policy_number'      => $event->policy_number,
                'provider_reference' => $event->provider_reference,
            ];
        }

        // Check if already has a policy number via a processed event
        $processedEvent = ProviderEvent::where('policy_request_id', $request->id)
            ->where('event_type', 'ISSUED')
            ->where('processed', true)
            ->first();

        if ($processedEvent) {
            return [
                'found'              => true,
                'status'             => 'ISSUED',
                'policy_number'      => $processedEvent->policy_number,
                'provider_reference' => $processedEvent->provider_reference,
            ];
        }

        return [
            'found'  => false,
            'status' => 'NOT_FOUND',
        ];
    }

    /**
     * Generate a deterministic (but unique) policy number.
     * Based on UUID prefix to guarantee uniqueness.
     */
    private function generatePolicyNumber(PolicyRequest $request): string
    {
        $prefix = 'POL-' . date('Y') . '-';
        $suffix = strtoupper(substr(str_replace('-', '', $request->request_uuid), 0, 10));
        return $prefix . $suffix;
    }
}
