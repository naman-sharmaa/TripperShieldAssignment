<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_uuid',
        'agent_id',
        'client_request_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'customer_age',
        'premium',
        'status',
        'policy_number',
        'provider_reference',
        'attempt_count',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start'    => 'date',
        'trip_end'      => 'date',
        'premium'       => 'decimal:2',
        'sum_insured'   => 'integer',
        'travel_days'   => 'integer',
        'customer_age'  => 'integer',
        'attempt_count' => 'integer',
    ];

    // Valid states
    const STATE_RECEIVED              = 'RECEIVED';
    const STATE_VALIDATED             = 'VALIDATED';
    const STATE_SUBMITTED             = 'SUBMITTED';
    const STATE_PENDING_CONFIRMATION  = 'PENDING_CONFIRMATION';
    const STATE_ISSUED                = 'ISSUED';
    const STATE_FAILED                = 'FAILED';
    const STATE_NEEDS_RECONCILIATION  = 'NEEDS_RECONCILIATION';

    const TERMINAL_STATES = [self::STATE_ISSUED];

    const VALID_TRANSITIONS = [
        self::STATE_RECEIVED             => [self::STATE_VALIDATED, self::STATE_FAILED],
        self::STATE_VALIDATED            => [self::STATE_SUBMITTED, self::STATE_FAILED],
        self::STATE_SUBMITTED            => [self::STATE_PENDING_CONFIRMATION, self::STATE_ISSUED, self::STATE_FAILED, self::STATE_NEEDS_RECONCILIATION],
        self::STATE_PENDING_CONFIRMATION => [self::STATE_ISSUED, self::STATE_FAILED, self::STATE_NEEDS_RECONCILIATION],
        self::STATE_NEEDS_RECONCILIATION => [self::STATE_ISSUED, self::STATE_FAILED, self::STATE_SUBMITTED],
        self::STATE_FAILED               => [self::STATE_VALIDATED, self::STATE_SUBMITTED], // retry
        self::STATE_ISSUED               => [], // terminal - no transitions allowed
    ];

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('transitioned_at');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES);
    }

    public function canTransitionTo(string $newState): bool
    {
        $allowed = self::VALID_TRANSITIONS[$this->status] ?? [];
        return in_array($newState, $allowed);
    }
}
