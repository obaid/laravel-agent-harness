<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_sessions', function (Blueprint $table): void {
            $table->string('id', 40)->primary();

            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('participant_type')->nullable();
            $table->string('participant_id')->nullable();

            $table->string('agent_class')->nullable();
            $table->string('runtime_name')->nullable();
            $table->string('driver', 64);

            $table->string('name')->nullable();
            $table->string('status', 32)->index();
            $table->string('permission_mode', 32);

            $table->string('conversation_id', 64)->nullable();
            $table->string('workspace_id', 64)->nullable();

            // Encrypted: may carry provider options and application context.
            $table->text('configuration')->nullable();
            $table->json('budget')->nullable();
            $table->json('metadata')->nullable();

            $table->string('active_run_id', 40)->nullable();

            $table->string('queue_connection')->nullable();
            $table->string('queue')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();

            $table->unsignedBigInteger('version')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_type', 'tenant_id', 'created_at'], 'ah_sessions_tenant_index');
            $table->index(['participant_type', 'participant_id', 'created_at'], 'ah_sessions_participant_index');
            $table->index(['status', 'updated_at'], 'ah_sessions_status_index');
            $table->index('deleted_at', 'ah_sessions_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clutch_sessions');
    }
};
