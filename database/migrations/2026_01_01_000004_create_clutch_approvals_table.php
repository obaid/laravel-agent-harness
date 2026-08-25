<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_approvals', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('session_id', 40);
            $table->string('run_id', 40);

            $table->string('tool_call_id', 128);
            $table->string('tool_name');

            // Encrypted: tool arguments routinely carry business-sensitive values.
            $table->text('arguments')->nullable();
            $table->text('edited_arguments')->nullable();

            $table->text('reason')->nullable();
            $table->string('status', 32);

            $table->timestamp('requested_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->string('resolved_by_type')->nullable();
            $table->string('resolved_by_id')->nullable();
            $table->text('decision_reason')->nullable();

            $table->unsignedBigInteger('version')->default(1);

            $table->timestamps();

            $table->foreign('run_id')
                ->references('id')->on('clutch_runs')
                ->cascadeOnDelete();

            $table->unique(['run_id', 'tool_call_id'], 'ah_approvals_call_unique');
            $table->index(['session_id', 'status'], 'ah_approvals_session_index');
            $table->index(['status', 'expires_at'], 'ah_approvals_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clutch_approvals');
    }
};
