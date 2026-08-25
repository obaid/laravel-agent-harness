<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_checkpoints', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('session_id', 40);
            $table->string('run_id', 40)->nullable();

            $table->string('driver', 64);
            $table->unsignedInteger('schema_version');
            $table->string('reason', 64);

            // Encrypted: opaque driver state restored by a later worker.
            $table->longText('payload');
            $table->string('payload_digest', 64);

            $table->unsignedBigInteger('event_sequence')->default(0);
            $table->boolean('portable')->default(false);

            $table->timestamp('created_at');

            $table->foreign('session_id')
                ->references('id')->on('clutch_sessions')
                ->cascadeOnDelete();

            $table->index(['session_id', 'created_at'], 'ah_checkpoints_session_index');
            $table->index(['run_id', 'created_at'], 'ah_checkpoints_run_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clutch_checkpoints');
    }
};
