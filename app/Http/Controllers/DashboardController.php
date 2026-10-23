<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $statusCounts = PolicyRequest::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        $allStatuses = [
            'RECEIVED', 'VALIDATED', 'SUBMITTED',
            'PENDING_CONFIRMATION', 'ISSUED', 'FAILED', 'NEEDS_RECONCILIATION',
        ];

        foreach ($allStatuses as $status) {
            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
        }

        $totalRequests = array_sum($statusCounts);

        return view('dashboard', compact('statusCounts', 'totalRequests'));
    }

    public function requests(Request $request): View
    {
        $query = PolicyRequest::orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $requests = $query->paginate(15)->withQueryString();

        $allStatuses = [
            'RECEIVED', 'VALIDATED', 'SUBMITTED',
            'PENDING_CONFIRMATION', 'ISSUED', 'FAILED', 'NEEDS_RECONCILIATION',
        ];

        return view('requests.index', compact('requests', 'allStatuses'));
    }

    public function showRequest(string $id): View
    {
        $policyRequest = PolicyRequest::with([
            'stateHistory',
            'issuanceAttempts',
            'providerEvents',
        ])->where('request_uuid', $id)->orWhere('id', $id)->firstOrFail();

        return view('requests.show', compact('policyRequest'));
    }

    public function createRequest(): View
    {
        return view('requests.create');
    }
}
