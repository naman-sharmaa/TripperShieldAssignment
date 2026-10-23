<?php

namespace Tests\Feature;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyRequestFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validPayload = [
            'agent_id'          => 'AGENT-001',
            'client_request_id' => 'REQ-' . uniqid(),
            'customer_name'     => 'Priya Sharma',
            'date_of_birth'     => '1990-03-15',
            'destination'       => 'INTERNATIONAL',
            'trip_start'        => '2025-06-01',
            'trip_end'          => '2025-06-05',
            'plan_code'         => 'PLUS',
            'sum_insured'       => 300000,
        ];
    }

    // -------------------------------------------------------------------------
    // Priority 2: Intake & Validation
    // -------------------------------------------------------------------------

    /** @test */
    public function test_valid_request_creates_policy_with_correct_premium(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('created', true);

        // PLUS, 5 days, age 35 (dob 1990-03-15, trip start 2025-06-01), INTERNATIONAL, 300000
        // = 20 * 5 * 1.0 * 1.8 * 1.25 = 225.00
        $data = $response->json('data');
        $this->assertEquals('225.00', $data['premium']);
        $this->assertEquals('RECEIVED', $data['status']);
        $this->assertNotNull($data['id']);
        $this->assertNotNull($data['travel_days']);
        $this->assertEquals(5, $data['travel_days']);
        $this->assertEquals(35, $data['customer_age']);

        $this->assertDatabaseHas('policy_requests', [
            'agent_id'          => 'AGENT-001',
            'client_request_id' => $this->validPayload['client_request_id'],
            'premium'           => '225.00',
        ]);
    }

    /** @test */
    public function test_invalid_trip_dates_rejected(): void
    {
        $payload = array_merge($this->validPayload, [
            'trip_start' => '2025-06-10',
            'trip_end'   => '2025-06-05', // end before start
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['trip_end']]);
    }

    /** @test */
    public function test_unsupported_plan_rejected(): void
    {
        $payload = array_merge($this->validPayload, ['plan_code' => 'GOLD']);
        $this->postJson('/api/policy-requests', $payload)->assertStatus(422);
    }

    /** @test */
    public function test_unsupported_coverage_rejected(): void
    {
        $payload = array_merge($this->validPayload, ['sum_insured' => 200000]);
        $this->postJson('/api/policy-requests', $payload)->assertStatus(422);
    }

    /** @test */
    public function test_invalid_destination_rejected(): void
    {
        $payload = array_merge($this->validPayload, ['destination' => 'EUROPE']);
        $this->postJson('/api/policy-requests', $payload)->assertStatus(422);
    }

    /** @test */
    public function test_missing_required_fields_rejected(): void
    {
        $this->postJson('/api/policy-requests', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // -------------------------------------------------------------------------
    // Priority 2: Idempotency
    // -------------------------------------------------------------------------

    /** @test */
    public function test_duplicate_intake_returns_same_logical_request(): void
    {
        $response1 = $this->postJson('/api/policy-requests', $this->validPayload);
        $response2 = $this->postJson('/api/policy-requests', $this->validPayload);

        $response1->assertStatus(201);
        $response2->assertStatus(200);

        $this->assertEquals(
            $response1->json('data.id'),
            $response2->json('data.id')
        );

        // Exactly ONE record in the DB
        $this->assertEquals(1, PolicyRequest::where([
            'agent_id'          => $this->validPayload['agent_id'],
            'client_request_id' => $this->validPayload['client_request_id'],
        ])->count());
    }

    /** @test */
    public function test_different_agent_same_client_request_id_allowed(): void
    {
        $p1 = array_merge($this->validPayload, ['agent_id' => 'AGENT-001']);
        $p2 = array_merge($this->validPayload, ['agent_id' => 'AGENT-002']);

        $this->postJson('/api/policy-requests', $p1)->assertStatus(201);
        $this->postJson('/api/policy-requests', $p2)->assertStatus(201);

        $this->assertEquals(2, PolicyRequest::count());
    }

    // -------------------------------------------------------------------------
    // Priority 3: Premium immutability
    // -------------------------------------------------------------------------

    /** @test */
    public function test_premium_unchanged_during_retry(): void
    {
        // Create request
        $r1 = $this->postJson('/api/policy-requests', $this->validPayload);
        $originalPremium = $r1->json('data.premium');
        $id = $r1->json('data.id');

        // Submit (TEMPORARY_FAILURE — will fail first time)
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'TEMPORARY_FAILURE']);

        // Check premium unchanged
        $request = PolicyRequest::where('request_uuid', $id)->first();
        $this->assertEquals($originalPremium, (string) $request->premium);
    }

    // -------------------------------------------------------------------------
    // Priority 4: Issuance workflow
    // -------------------------------------------------------------------------

    /** @test */
    public function test_successful_issuance_produces_exactly_one_policy_number(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'SUCCESS']);

        $request = PolicyRequest::where('request_uuid', $id)->with('issuanceAttempts')->first();

        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $this->assertNotNull($request->policy_number);

        // Only one unique policy number across all attempts
        $policyNumbers = $request->issuanceAttempts->pluck('policy_number')->filter()->unique();
        $this->assertCount(1, $policyNumbers);

        // Only one policy_number in DB for this request
        $this->assertEquals(1, PolicyRequest::where('request_uuid', $id)->whereNotNull('policy_number')->count());
    }

    /** @test */
    public function test_issued_state_cannot_be_overwritten(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        // Issue successfully
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'SUCCESS']);

        $request = PolicyRequest::where('request_uuid', $id)->first();
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $originalPolicyNumber = $request->policy_number;

        // Attempt to re-submit — should be idempotent, not create new policy
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'SUCCESS']);

        $request->refresh();
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $this->assertEquals($originalPolicyNumber, $request->policy_number);
    }

    // -------------------------------------------------------------------------
    // Priority 5: Reliability scenarios
    // -------------------------------------------------------------------------

    /** @test */
    public function test_timeout_after_issue_moves_to_needs_reconciliation(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        $response = $this->postJson("/api/policy-requests/{$id}/submit", [
            'mock_scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $request = PolicyRequest::where('request_uuid', $id)->first();
        $this->assertEquals(PolicyRequest::STATE_NEEDS_RECONCILIATION, $request->status);
        $this->assertNull($request->policy_number); // Not yet set by app
    }

    /** @test */
    public function test_timeout_followed_by_reconciliation_recovers_policy_without_duplicate(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        // Submit with timeout scenario
        $this->postJson("/api/policy-requests/{$id}/submit", [
            'mock_scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $request = PolicyRequest::where('request_uuid', $id)->first();
        $this->assertEquals(PolicyRequest::STATE_NEEDS_RECONCILIATION, $request->status);

        // Reconcile — should recover the existing policy
        $this->postJson("/api/policy-requests/{$id}/reconcile");

        $request->refresh();
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $this->assertNotNull($request->policy_number);

        // Verify only ONE policy number exists across all issuance attempts
        $policyNumbers = IssuanceAttempt::where('policy_request_id', $request->id)
            ->pluck('policy_number')
            ->filter()
            ->unique();
        $this->assertLessThanOrEqual(1, $policyNumbers->count());
    }

    /** @test */
    public function test_duplicate_callbacks_do_not_duplicate_processing(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        // Submit and get to PENDING state
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'DELAYED_CALLBACK']);

        $request = PolicyRequest::where('request_uuid', $id)->first();

        // Send first callback
        $callbackPayload = [
            'provider_event_id'  => 'EVT-DUPLICATE-TEST-001',
            'provider_reference' => $request->provider_reference,
            'event_type'         => 'ISSUED',
            'policy_number'      => 'POL-DUPLICATE-TEST-001',
        ];

        $this->postJson('/api/mock-insurer/callback', $callbackPayload)->assertStatus(200);
        $request->refresh();
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $firstPolicyNumber = $request->policy_number;

        // Send duplicate callback
        $this->postJson('/api/mock-insurer/callback', $callbackPayload)->assertStatus(200);
        $request->refresh();

        // State must still be ISSUED with same policy number
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $this->assertEquals($firstPolicyNumber, $request->policy_number);

        // Only one provider event should be processed
        $processedCount = ProviderEvent::where('provider_event_id', 'EVT-DUPLICATE-TEST-001')
            ->where('processed', true)
            ->count();
        $this->assertEquals(1, $processedCount);
    }

    /** @test */
    public function test_temporary_failure_allows_retry(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        // First attempt — fails
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'TEMPORARY_FAILURE']);
        $request = PolicyRequest::where('request_uuid', $id)->first();
        $this->assertEquals(PolicyRequest::STATE_FAILED, $request->status);

        // Second attempt — fails again (attempt_count=1 which is <2)
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'TEMPORARY_FAILURE']);
        $request->refresh();
        $this->assertEquals(PolicyRequest::STATE_FAILED, $request->status);

        // Third attempt — succeeds (attempt_count >= 2)
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'TEMPORARY_FAILURE']);
        $request->refresh();
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $this->assertNotNull($request->policy_number);
    }

    // -------------------------------------------------------------------------
    // API endpoints
    // -------------------------------------------------------------------------

    /** @test */
    public function test_get_all_requests_returns_paginated_list(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload);

        $response = $this->getJson('/api/policy-requests');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
    }

    /** @test */
    public function test_get_request_by_uuid_returns_details(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        $response = $this->getJson("/api/policy-requests/{$id}");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id)
            ->assertJsonStructure(['data' => ['state_history', 'issuance_attempts']]);
    }

    /** @test */
    public function test_get_nonexistent_request_returns_404(): void
    {
        $this->getJson('/api/policy-requests/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    /** @test */
    public function test_status_filter_returns_only_matching_requests(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload);

        $response = $this->getJson('/api/policy-requests?status=RECEIVED');
        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertEquals('RECEIVED', $item['status']);
        }
    }

    /** @test */
    public function test_artisan_reconcile_command_reconciles_requests(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        // Submit with timeout so it enters NEEDS_RECONCILIATION
        $this->postJson("/api/policy-requests/{$id}/submit", ['mock_scenario' => 'TIMEOUT_AFTER_ISSUE']);
        $request = PolicyRequest::where('request_uuid', $id)->first();
        $this->assertEquals(PolicyRequest::STATE_NEEDS_RECONCILIATION, $request->status);

        // Run artisan command
        $this->artisan('policy:reconcile', ['--id' => $id])
            ->assertSuccessful();

        $request->refresh();
        $this->assertEquals(PolicyRequest::STATE_ISSUED, $request->status);
        $this->assertNotNull($request->policy_number);
    }

    /** @test */
    public function test_health_check_returns_ok(): void
    {
        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');
    }

    /** @test */
    public function test_web_routes_render_successfully(): void
    {
        $r = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $r->json('data.id');

        $this->get('/')->assertStatus(200);
        $this->get('/requests')->assertStatus(200);
        $this->get('/requests/create')->assertStatus(200);
        $this->get("/requests/{$id}")->assertStatus(200);
    }
}

