<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_runs', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('session_id', 40);

            $table->unsignedInteger('attempt')->default(1);
            $table->string('retry_of_run_id', 40)->nullable();
            $table->string('idempotency_key')->nullable();

            $table->string('status', 32);
            $table->string('input_type', 32)->default('prompt');

            // Encrypted: user input may contain business-sensitive content.
            $table->text('input')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('structured_output')->nullable();

            $table->json('usage')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();

            $table->unsignedBigInteger('last_event_sequence')->default(0);
            $table->string('last_checkpoint_id', 40)->nullable();

            $table->timestamp('cancellation_requested_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->string('failure_category', 32)->nullable();
            $table->text('failure_message')->nullable();
            $table->string('failure_exception_class')->nullable();

            $table->json('budget')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();

            $table->unsignedBigInteger('version')->default(1);

            $table->timestamps();

            $table->foreign('session_id')
                ->references('id')->on('clutch_sessions')
                ->cascadeOnDelete();

            $table->index(['session_id', 'created_at'], 'ah_runs_session_index');
            $table->index(['status', 'created_at'], 'ah_runs_status_index');
            $table->index(['status', 'heartbeat_at'], 'ah_runs_heartbeat_index');
            $table->index('finished_at', 'ah_runs_finished_index');

            $table->unique(['session_id', 'idempotency_key'], 'ah_runs_idempotency_unique');
        });

        Schema::table('clutch_sessions', function (Blueprint $table): void {
            $table->foreign('active_run_id')
                ->references('id')->on('clutch_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clutch_sessions', function (Blueprint $table): void {
            $table->dropForeign(['active_run_id']);
        });

        Schema::dropIfExists('clutch_runs');
    }
};
