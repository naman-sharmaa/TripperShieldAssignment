<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->foreignId('issuance_attempt_id')->nullable()->constrained('issuance_attempts')->nullOnDelete();

            // Provider's own event identifier - must be unique to prevent duplicate processing
            $table->string('provider_event_id', 200)->unique();
            $table->string('event_type', 50); // ISSUED, FAILED, TIMEOUT_RECOVERY, etc.
            $table->string('policy_number', 100)->nullable();
            $table->string('provider_reference', 200)->nullable();
            $table->text('payload')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('event_timestamp');

            $table->timestamps();

            $table->index('policy_request_id');
            $table->index('processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
