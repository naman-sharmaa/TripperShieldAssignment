<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->unique();

            // Idempotency key - DB-enforced unique per agent+client_request_id
            $table->string('agent_id', 100);
            $table->string('client_request_id', 100);
            $table->unique(['agent_id', 'client_request_id'], 'uq_agent_client_request');

            // Customer details
            $table->string('customer_name', 200);
            $table->date('date_of_birth');
            $table->enum('destination', ['DOMESTIC', 'INTERNATIONAL']);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->enum('plan_code', ['BASIC', 'PLUS', 'PREMIUM']);
            $table->unsignedInteger('sum_insured');

            // Calculated snapshot (immutable after creation)
            $table->unsignedSmallInteger('travel_days');
            $table->unsignedTinyInteger('customer_age');
            $table->decimal('premium', 10, 2);

            // Issuance tracking
            $table->string('status', 30)->default('RECEIVED');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 200)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);

            $table->timestamps();

            $table->index('status');
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
