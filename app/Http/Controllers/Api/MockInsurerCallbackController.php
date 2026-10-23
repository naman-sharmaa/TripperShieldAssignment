<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MockInsurerCallbackController extends Controller
{
    public function __construct(
        private readonly IssuanceService $issuanceService,
    ) {}

    /**
     * POST /api/mock-insurer/callback
     *
     * Accepts a simulated provider event callback.
     *
     * Request body:
     * {
     *   "provider_event_id": "EVT-ABC123",       // required — unique event ID (idempotency key)
     *   "provider_reference": "MOCK-XYZ789",     // required — matches the submission reference
     *   "event_type": "ISSUED",                  // required — ISSUED | FAILED
     *   "policy_number": "POL-2024-ABC123",      // required if ISSUED
     *   "event_timestamp": "2024-01-01T10:00:00Z" // optional
     * }
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_event_id'  => ['required', 'string', 'max:200'],
            'provider_reference' => ['required', 'string', 'max:200'],
            'event_type'         => ['required', 'in:ISSUED,FAILED'],
            'policy_number'      => ['nullable', 'string', 'max:100'],
            'event_timestamp'    => ['nullable', 'date'],
        ]);

        // Idempotency: if already processed, return 200 silently
        $existing = ProviderEvent::where('provider_event_id', $validated['provider_event_id'])->first();
        if ($existing && $existing->processed) {
            return response()->json([
                'success' => true,
                'message' => 'Event already processed (idempotent).',
                'event_id' => $validated['provider_event_id'],
            ]);
        }

        // Find the policy request by provider_reference
        $policyRequest = PolicyRequest::where('provider_reference', $validated['provider_reference'])->first();

        if (!$policyRequest) {
            // Could be a delayed callback for a request we haven't seen — store the event
            Log::warning('Callback received for unknown provider_reference', $validated);
            return response()->json([
                'success' => false,
                'message' => 'No policy request found with the given provider_reference.',
            ], 404);
        }

        try {
            $updated = $this->issuanceService->processCallback(
                request:             $policyRequest,
                providerEventId:     $validated['provider_event_id'],
                eventType:           $validated['event_type'],
                policyNumber:        $validated['policy_number'] ?? null,
                providerReference:   $validated['provider_reference'],
                payload:             $validated,
            );

            return response()->json([
                'success'  => true,
                'message'  => 'Callback processed.',
                'status'   => $updated->status,
                'policy_number' => $updated->policy_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Callback processing error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error processing callback.',
            ], 500);
        }
    }

    /**
     * GET /api/mock-insurer/status/{providerReference}
     * Returns mock provider's view of a reference (for reconciliation).
     */
    public function status(string $providerReference): JsonResponse
    {
        $event = ProviderEvent::where('provider_reference', $providerReference)
            ->where('event_type', 'ISSUED')
            ->first();

        if ($event) {
            return response()->json([
                'found'              => true,
                'status'             => 'ISSUED',
                'policy_number'      => $event->policy_number,
                'provider_reference' => $providerReference,
            ]);
        }

        return response()->json([
            'found'  => false,
            'status' => 'NOT_FOUND',
        ]);
    }
}
