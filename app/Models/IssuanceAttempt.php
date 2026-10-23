<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'idempotency_key',
        'attempt_number',
        'status',
        'provider_reference',
        'policy_number',
        'request_payload',
        'response_payload',
        'error_message',
        'attempted_at',
        'responded_at',
    ];

    protected $casts = [
        'attempted_at'  => 'datetime',
        'responded_at'  => 'datetime',
        'attempt_number' => 'integer',
    ];

    const STATUS_PENDING = 'PENDING';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED  = 'FAILED';
    const STATUS_TIMEOUT = 'TIMEOUT';

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
