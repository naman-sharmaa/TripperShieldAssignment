<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * IssuanceService — orchestrates the full issuance workflow.
 */
class IssuanceService
{
    public function __construct(
        private readonly PolicyStateManager $stateManager,
        private readonly MockInsurerService $mockInsurer,
    ) {}

    /**
     * Submit a policy request to the mock insurer.
     * Handles idempotency: will not re-submit an already-ISSUED request.
     * Handles timeout recovery: queries provider before retrying.
     */
    public function submit(PolicyRequest $request, string $scenario = 'SUCCESS'): PolicyRequest
    {
        // Terminal: already issued — idempotent return
        if ($request->status === PolicyRequest::STATE_ISSUED) {
            return $request;
        }

        // If NEEDS_RECONCILIATION: reconcile first
        if ($request->status === PolicyRequest::STATE_NEEDS_RECONCILIATION) {
            return $this->reconcile($request);
        }

        // Validate state allows submission
        $allowedStates = [
            PolicyRequest::STATE_VALIDATED,
            PolicyRequest::STATE_RECEIVED,
            PolicyRequest::STATE_FAILED,
            PolicyRequest::STATE_SUBMITTED, // retry
        ];
        if (!in_array($request->status, $allowedStates)) {
            throw new RuntimeException("Cannot submit request in state: {$request->status}");
        }

        // Transition to VALIDATED then SUBMITTED
        if (!in_array($request->status, [PolicyRequest::STATE_SUBMITTED])) {
            $request = $this->stateManager->safeTransition(
                $request,
                PolicyRequest::STATE_VALIDATED,
                'Pre-submission validation passed',
                'submit_api'
            );
            $request = $this->stateManager->safeTransition(
                $request,
                PolicyRequest::STATE_SUBMITTED,
                'Submitting to provider',
                'submit_api'
            );
        }

        // Create attempt record
        $attemptNumber = $request->attempt_count + 1;
        $idempotencyKey = $request->request_uuid . '-attempt-' . $attemptNumber;

        $attempt = IssuanceAttempt::create([
            'policy_request_id' => $request->id,
            'idempotency_key'   => $idempotencyKey,
            'attempt_number'    => $attemptNumber,
            'status'            => IssuanceAttempt::STATUS_PENDING,
            'request_payload'   => json_encode([
                'request_uuid' => $request->request_uuid,
                'plan_code'    => $request->plan_code,
                'premium'      => $request->premium,
                'scenario'     => $scenario,
            ]),
            'attempted_at'      => now(),
        ]);

        // Update attempt count
        $request->increment('attempt_count');
        $request->refresh();

        // Call mock insurer
        $response = $this->mockInsurer->submit($request, $scenario);

        // Update attempt with response
        $attempt->update([
            'status'             => match ($response['status']) {
                'SUCCESS' => IssuanceAttempt::STATUS_SUCCESS,
                'TIMEOUT' => IssuanceAttempt::STATUS_TIMEOUT,
                'PENDING' => IssuanceAttempt::STATUS_PENDING,
                default   => IssuanceAttempt::STATUS_FAILED,
            },
            'provider_reference' => $response['provider_reference'],
            'policy_number'      => $response['policy_number'],
            'response_payload'   => json_encode($response),
            'error_message'      => $response['error_message'],
            'responded_at'       => now(),
        ]);

        // Process response
        return match ($response['status']) {
            'SUCCESS' => $this->handleSuccess($request, $response, $attempt),
            'TIMEOUT' => $this->handleTimeout($request, $response, $attempt),
            'PENDING' => $this->handlePending($request, $response),
            default   => $this->handleFailure($request, $response),
        };
    }

    /**
     * Reconcile a request in NEEDS_RECONCILIATION state.
     * Queries mock provider for actual policy status.
     */
    public function reconcile(PolicyRequest $request): PolicyRequest
    {
        $providerStatus = $this->mockInsurer->lookupStatus($request);

        if ($providerStatus['found'] && $providerStatus['status'] === 'ISSUED') {
            // Policy was actually issued — recover it
            return DB::transaction(function () use ($request, $providerStatus) {
                // Mark the unprocessed event as processed
                ProviderEvent::where('policy_request_id', $request->id)
                    ->where('event_type', 'ISSUED')
                    ->where('processed', false)
                    ->update([
                        'processed'    => true,
                        'processed_at' => now(),
                    ]);

                $request->policy_number      = $providerStatus['policy_number'];
                $request->provider_reference = $providerStatus['provider_reference'];
                $request->save();

                return $this->stateManager->safeTransition(
                    $request,
                    PolicyRequest::STATE_ISSUED,
                    'Reconciled from provider lookup — policy was already issued',
                    'reconciliation'
                );
            });
        }

        // Not found — transition back to FAILED for retry
        return $this->stateManager->safeTransition(
            $request,
            PolicyRequest::STATE_FAILED,
            'Reconciliation: provider confirms not issued',
            'reconciliation'
        );
    }

    private function handleSuccess(PolicyRequest $request, array $response, IssuanceAttempt $attempt): PolicyRequest
    {
        return DB::transaction(function () use ($request, $response, $attempt) {
            // Guard: check current state under lock
            $locked = PolicyRequest::lockForUpdate()->find($request->id);
            if ($locked->status === PolicyRequest::STATE_ISSUED) {
                return $locked; // Already issued — idempotent
            }

            // Store provider event
            ProviderEvent::create([
                'policy_request_id'   => $request->id,
                'issuance_attempt_id' => $attempt->id,
                'provider_event_id'   => 'EVT-SUCCESS-' . strtoupper(Str::random(8)),
                'event_type'          => 'ISSUED',
                'policy_number'       => $response['policy_number'],
                'provider_reference'  => $response['provider_reference'],
                'payload'             => json_encode($response),
                'processed'           => true,
                'processed_at'        => now(),
                'event_timestamp'     => now(),
            ]);

            $locked->policy_number      = $response['policy_number'];
            $locked->provider_reference = $response['provider_reference'];
            $locked->save();

            return $this->stateManager->transition(
                $locked,
                PolicyRequest::STATE_ISSUED,
                'Policy issued by provider',
                'mock_insurer'
            );
        });
    }

    private function handleTimeout(PolicyRequest $request, array $response, IssuanceAttempt $attempt): PolicyRequest
    {
        // Move to NEEDS_RECONCILIATION — the policy may have been created on the provider side
        $request->provider_reference = $response['provider_reference'];
        $request->save();

        return $this->stateManager->safeTransition(
            $request,
            PolicyRequest::STATE_NEEDS_RECONCILIATION,
            'Provider timed out. Policy may have been issued. Reconciliation required.',
            'timeout_handler'
        );
    }

    private function handlePending(PolicyRequest $request, array $response): PolicyRequest
    {
        $request->provider_reference = $response['provider_reference'];
        $request->save();

        return $this->stateManager->safeTransition(
            $request,
            PolicyRequest::STATE_PENDING_CONFIRMATION,
            'Waiting for provider callback',
            'mock_insurer'
        );
    }

    private function handleFailure(PolicyRequest $request, array $response): PolicyRequest
    {
        return $this->stateManager->safeTransition(
            $request,
            PolicyRequest::STATE_FAILED,
            'Provider returned failure: ' . ($response['error_message'] ?? 'Unknown error'),
            'mock_insurer'
        );
    }

    /**
     * Process an incoming provider callback event (idempotent).
     */
    public function processCallback(
        PolicyRequest $request,
        string        $providerEventId,
        string        $eventType,
        ?string       $policyNumber,
        ?string       $providerReference,
        array         $payload = []
    ): PolicyRequest {
        return DB::transaction(function () use (
            $request, $providerEventId, $eventType, $policyNumber, $providerReference, $payload
        ) {
            // Idempotency: check if this event was already processed
            $existingEvent = ProviderEvent::where('provider_event_id', $providerEventId)->first();

            if ($existingEvent && $existingEvent->processed) {
                // Already processed — idempotent return
                return $request->fresh();
            }

            // Lock request row
            $request = PolicyRequest::lockForUpdate()->find($request->id);

            // If already in terminal state, record event but don't change state
            if ($request->isTerminal()) {
                if (!$existingEvent) {
                    ProviderEvent::create([
                        'policy_request_id'  => $request->id,
                        'issuance_attempt_id'=> null,
                        'provider_event_id'  => $providerEventId,
                        'event_type'         => $eventType,
                        'policy_number'      => $policyNumber,
                        'provider_reference' => $providerReference,
                        'payload'            => json_encode($payload),
                        'processed'          => true, // no-op but recorded
                        'processed_at'       => now(),
                        'event_timestamp'    => now($payload['event_timestamp'] ?? null),
                    ]);
                }
                return $request;
            }

            // Create or update event record
            if (!$existingEvent) {
                $existingEvent = ProviderEvent::create([
                    'policy_request_id'  => $request->id,
                    'issuance_attempt_id'=> null,
                    'provider_event_id'  => $providerEventId,
                    'event_type'         => $eventType,
                    'policy_number'      => $policyNumber,
                    'provider_reference' => $providerReference,
                    'payload'            => json_encode($payload),
                    'processed'          => false,
                    'event_timestamp'    => now(),
                ]);
            }

            // Process event
            if ($eventType === 'ISSUED' && $policyNumber) {
                $request->policy_number      = $policyNumber;
                $request->provider_reference = $providerReference;
                $request->save();

                $request = $this->stateManager->safeTransition(
                    $request,
                    PolicyRequest::STATE_ISSUED,
                    "Callback received: policy issued",
                    'callback'
                );
            } elseif ($eventType === 'FAILED') {
                $request = $this->stateManager->safeTransition(
                    $request,
                    PolicyRequest::STATE_FAILED,
                    "Callback received: provider failure",
                    'callback'
                );
            }

            $existingEvent->update([
                'processed'    => true,
                'processed_at' => now(),
            ]);

            return $request;
        });
    }
}
