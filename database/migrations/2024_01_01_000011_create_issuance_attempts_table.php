<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();

            $table->string('idempotency_key', 200)->unique();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 30); // PENDING, SUCCESS, FAILED, TIMEOUT
            $table->string('provider_reference', 200)->nullable();
            $table->string('policy_number', 100)->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamp('attempted_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
