<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    protected $fillable = [
        'policy_request_id',
        'issuance_attempt_id',
        'provider_event_id',
        'event_type',
        'policy_number',
        'provider_reference',
        'payload',
        'processed',
        'processed_at',
        'event_timestamp',
    ];

    protected $casts = [
        'processed'       => 'boolean',
        'processed_at'    => 'datetime',
        'event_timestamp' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }

    public function issuanceAttempt(): BelongsTo
    {
        return $this->belongsTo(IssuanceAttempt::class);
    }
}
