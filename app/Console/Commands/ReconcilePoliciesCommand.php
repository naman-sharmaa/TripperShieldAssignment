<?php

namespace App\Console\Commands;

use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{
    protected $signature = 'policy:reconcile 
                            {--minutes=5 : Age threshold in minutes for pending requests} 
                            {--id= : Specific policy request UUID to reconcile}';

    protected $description = 'Find stale pending or needs-reconciliation policy requests and reconcile with provider';

    public function handle(IssuanceService $issuanceService): int
    {
        $specificId = $this->option('id');

        if ($specificId) {
            $request = PolicyRequest::where('request_uuid', $specificId)
                ->orWhere('id', $specificId)
                ->first();

            if (!$request) {
                $this->error("Policy request {$specificId} not found.");
                return self::FAILURE;
            }

            $this->info("Reconciling specific request: {$request->request_uuid} [{$request->status}]");
            $reconciled = $issuanceService->reconcile($request);
            $this->info("New status: {$reconciled->status}" . ($reconciled->policy_number ? " (Policy: {$reconciled->policy_number})" : ''));
            return self::SUCCESS;
        }

        $minutes = (int) $this->option('minutes');
        $thresholdTime = now()->subMinutes($minutes);

        // Find requests needing reconciliation or stale in pending states
        $staleRequests = PolicyRequest::query()
            ->where(function ($query) use ($thresholdTime) {
                $query->where('status', PolicyRequest::STATE_NEEDS_RECONCILIATION)
                    ->orWhere(function ($q) use ($thresholdTime) {
                        $q->whereIn('status', [
                            PolicyRequest::STATE_PENDING_CONFIRMATION,
                            PolicyRequest::STATE_SUBMITTED,
                        ])->where('updated_at', '<=', $thresholdTime);
                    });
            })
            ->get();

        $this->info("Found {$staleRequests->count()} requests requiring reconciliation.");

        $successCount = 0;
        foreach ($staleRequests as $request) {
            $this->line("Processing [{$request->request_uuid}] (Status: {$request->status})...");
            try {
                $result = $issuanceService->reconcile($request);
                $this->info("  -> Reconciled to: {$result->status}" . ($result->policy_number ? " [Policy: {$result->policy_number}]" : ''));
                $successCount++;
            } catch (\Throwable $e) {
                $this->error("  -> Failed: {$e->getMessage()}");
            }
        }

        $this->info("Reconciliation complete. {$successCount}/{$staleRequests->count()} processed successfully.");
        return self::SUCCESS;
    }
}
