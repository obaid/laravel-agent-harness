<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_tool_executions', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('session_id', 40);
            $table->string('run_id', 40);

            $table->string('tool_call_id', 128);
            $table->string('tool_name');
            $table->string('idempotency_key', 191)->nullable();
            $table->string('arguments_digest', 64);

            $table->string('status', 32);

            // Encrypted: stored so a retry can return the original result
            // without repeating the side effect.
            $table->longText('result')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->foreign('run_id')
                ->references('id')->on('clutch_runs')
                ->cascadeOnDelete();

            // One durable record per side effect, scoped to the owning session
            // so a key remains meaningful across run attempts.
            $table->unique(['session_id', 'idempotency_key'], 'ah_tool_idempotency_unique');
            $table->index(['run_id', 'tool_call_id'], 'ah_tool_call_index');
            $table->index('created_at', 'ah_tool_pruning_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clutch_tool_executions');
    }
};
