<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequestRequest;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\PolicyStateManager;
use App\Services\PremiumCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PolicyRequestController extends Controller
{
    public function __construct(
        private readonly PremiumCalculator $calculator,
        private readonly IssuanceService  $issuanceService,
        private readonly PolicyStateManager $stateManager,
    ) {}

    /**
     * POST /api/policy-requests
     * Idempotent intake — same agent_id+client_request_id returns original.
     */
    public function store(StorePolicyRequestRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Parse dates
        $tripStart = new \DateTime($data['trip_start']);
        $tripEnd   = new \DateTime($data['trip_end']);
        $dob       = new \DateTime($data['date_of_birth']);

        // Calculate age on trip_start date (not today)
        $age        = $this->calculator->calculateAge($dob, $tripStart);
        $travelDays = $this->calculator->calculateTravelDays($tripStart, $tripEnd);
        $premium    = $this->calculator->calculate(
            $data['plan_code'],
            $travelDays,
            $age,
            $data['destination'],
            (int) $data['sum_insured']
        );

        // Attempt atomic insert — if duplicate key, return existing
        try {
            $policyRequest = DB::transaction(function () use ($data, $age, $travelDays, $premium) {
                // Lock+check idempotency key — handles race conditions
                $existing = PolicyRequest::where('agent_id', $data['agent_id'])
                    ->where('client_request_id', $data['client_request_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['request' => $existing, 'created' => false];
                }

                $request = PolicyRequest::create([
                    'request_uuid'      => Str::uuid()->toString(),
                    'agent_id'          => $data['agent_id'],
                    'client_request_id' => $data['client_request_id'],
                    'customer_name'     => $data['customer_name'],
                    'date_of_birth'     => $data['date_of_birth'],
                    'destination'       => $data['destination'],
                    'trip_start'        => $data['trip_start'],
                    'trip_end'          => $data['trip_end'],
                    'plan_code'         => $data['plan_code'],
                    'sum_insured'       => (int) $data['sum_insured'],
                    'travel_days'       => $travelDays,
                    'customer_age'      => $age,
                    'premium'           => $premium,
                    'status'            => PolicyRequest::STATE_RECEIVED,
                    'attempt_count'     => 0,
                ]);

                return ['request' => $request, 'created' => true];
            });

            $statusCode = $policyRequest['created'] ? 201 : 200;

            return response()->json([
                'success'     => true,
                'created'     => $policyRequest['created'],
                'message'     => $policyRequest['created']
                    ? 'Policy request created successfully.'
                    : 'Existing policy request returned (idempotent).',
                'data'        => $this->formatRequest($policyRequest['request']),
            ], $statusCode);

        } catch (\Illuminate\Database\QueryException $e) {
            // Handle DB-level duplicate key (race condition safety net)
            if ($e->errorInfo[1] === 1062) {
                $existing = PolicyRequest::where('agent_id', $data['agent_id'])
                    ->where('client_request_id', $data['client_request_id'])
                    ->first();

                return response()->json([
                    'success' => true,
                    'created' => false,
                    'message' => 'Existing policy request returned (idempotent).',
                    'data'    => $this->formatRequest($existing),
                ], 200);
            }

            Log::error('PolicyRequest store error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected database error occurred.',
            ], 500);
        }
    }

    /**
     * GET /api/policy-requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::with(['stateHistory', 'issuanceAttempts'])
            ->orderByDesc('created_at');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $results = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $results->map(fn($r) => $this->formatRequest($r)),
            'meta'    => [
                'current_page' => $results->currentPage(),
                'last_page'    => $results->lastPage(),
                'per_page'     => $results->perPage(),
                'total'        => $results->total(),
            ],
        ]);
    }

    /**
     * GET /api/policy-requests/{id}
     */
    public function show(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with([
            'stateHistory',
            'issuanceAttempts',
            'providerEvents',
        ])->where('request_uuid', $id)->orWhere('id', $id)->first();

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatRequest($policyRequest, detailed: true),
        ]);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('request_uuid', $id)->orWhere('id', $id)->first();

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found.',
            ], 404);
        }

        $scenario = $request->input('mock_scenario', 'SUCCESS');
        $validScenarios = ['SUCCESS', 'TEMPORARY_FAILURE', 'TIMEOUT_AFTER_ISSUE', 'DELAYED_CALLBACK', 'DUPLICATE_CALLBACK'];

        if (!in_array($scenario, $validScenarios)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid mock_scenario. Must be one of: ' . implode(', ', $validScenarios),
            ], 422);
        }

        try {
            $updated = $this->issuanceService->submit($policyRequest, $scenario);

            return response()->json([
                'success' => true,
                'message' => 'Submission processed.',
                'data'    => $this->formatRequest($updated->fresh(['stateHistory', 'issuanceAttempts'])),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Submit error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during submission.',
            ], 500);
        }
    }

    /**
     * POST /api/policy-requests/{id}/reconcile
     */
    public function reconcile(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('request_uuid', $id)->orWhere('id', $id)->first();

        if (!$policyRequest) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $updated = $this->issuanceService->reconcile($policyRequest);

        return response()->json([
            'success' => true,
            'message' => 'Reconciliation complete.',
            'data'    => $this->formatRequest($updated->fresh(['stateHistory', 'issuanceAttempts'])),
        ]);
    }

    private function formatRequest(PolicyRequest $r, bool $detailed = false): array
    {
        $base = [
            'id'                => $r->request_uuid,
            'agent_id'          => $r->agent_id,
            'client_request_id' => $r->client_request_id,
            'customer_name'     => $r->customer_name,
            'date_of_birth'     => $r->date_of_birth?->format('Y-m-d'),
            'destination'       => $r->destination,
            'trip_start'        => $r->trip_start?->format('Y-m-d'),
            'trip_end'          => $r->trip_end?->format('Y-m-d'),
            'plan_code'         => $r->plan_code,
            'sum_insured'       => $r->sum_insured,
            'travel_days'       => $r->travel_days,
            'customer_age'      => $r->customer_age,
            'premium'           => $r->premium,
            'status'            => $r->status,
            'policy_number'     => $r->policy_number,
            'provider_reference'=> $r->provider_reference,
            'attempt_count'     => $r->attempt_count,
            'created_at'        => $r->created_at?->toIso8601String(),
            'updated_at'        => $r->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['state_history'] = $r->stateHistory?->map(fn($h) => [
                'from'          => $h->from_state,
                'to'            => $h->to_state,
                'reason'        => $h->reason,
                'triggered_by'  => $h->triggered_by,
                'at'            => $h->transitioned_at?->toIso8601String(),
            ])->toArray() ?? [];

            $base['issuance_attempts'] = $r->issuanceAttempts?->map(fn($a) => [
                'attempt_number'     => $a->attempt_number,
                'status'             => $a->status,
                'provider_reference' => $a->provider_reference,
                'policy_number'      => $a->policy_number,
                'error_message'      => $a->error_message,
                'attempted_at'       => $a->attempted_at?->toIso8601String(),
                'responded_at'       => $a->responded_at?->toIso8601String(),
            ])->toArray() ?? [];

            $base['provider_events'] = $r->providerEvents?->map(fn($e) => [
                'event_id'           => $e->provider_event_id,
                'event_type'         => $e->event_type,
                'policy_number'      => $e->policy_number,
                'processed'          => $e->processed,
                'event_timestamp'    => $e->event_timestamp?->toIso8601String(),
            ])->toArray() ?? [];
        }

        return $base;
    }
}
