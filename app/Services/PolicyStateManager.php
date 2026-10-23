<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PolicyStateManager — centralized state-transition mechanism.
 * All transitions must go through this service.
 */
class PolicyStateManager
{
    /**
     * Attempt to transition a PolicyRequest to a new state.
     * Records the transition in policy_state_history.
     *
     * @throws RuntimeException if the transition is invalid
     */
    public function transition(
        PolicyRequest $request,
        string        $newState,
        string        $reason = '',
        string        $triggeredBy = 'api'
    ): PolicyRequest {
        if (!$request->canTransitionTo($newState)) {
            throw new RuntimeException(
                "Invalid state transition from [{$request->status}] to [{$newState}] " .
                "for request [{$request->request_uuid}]."
            );
        }

        return DB::transaction(function () use ($request, $newState, $reason, $triggeredBy) {
            $oldState = $request->status;

            // Lock the row
            $request = PolicyRequest::lockForUpdate()->find($request->id);

            // Re-check after lock — another process may have changed state
            if ($request->status === PolicyRequest::STATE_ISSUED) {
                // Already issued; do not overwrite
                return $request;
            }

            // Re-validate under lock
            if (!$request->canTransitionTo($newState)) {
                throw new RuntimeException(
                    "Invalid state transition from [{$request->status}] to [{$newState}] " .
                    "after lock for request [{$request->request_uuid}]."
                );
            }

            $request->status = $newState;
            $request->save();

            PolicyStateHistory::create([
                'policy_request_id' => $request->id,
                'from_state'        => $oldState,
                'to_state'          => $newState,
                'reason'            => $reason,
                'triggered_by'      => $triggeredBy,
                'transitioned_at'   => now(),
            ]);

            return $request->fresh();
        });
    }

    /**
     * Force a safe transition — for ISSUED final state, silently ignore duplicate calls.
     */
    public function safeTransition(
        PolicyRequest $request,
        string        $newState,
        string        $reason = '',
        string        $triggeredBy = 'api'
    ): PolicyRequest {
        if ($request->status === $newState) {
            return $request;
        }
        if ($request->isTerminal()) {
            return $request;
        }
        return $this->transition($request, $newState, $reason, $triggeredBy);
    }
}
