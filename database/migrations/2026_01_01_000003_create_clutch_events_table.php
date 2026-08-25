<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_events', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('session_id', 40);
            $table->string('run_id', 40);

            $table->unsignedBigInteger('sequence');
            $table->string('type', 64);
            $table->json('payload');

            $table->timestamp('occurred_at', 6);

            $table->foreign('run_id')
                ->references('id')->on('clutch_runs')
                ->cascadeOnDelete();

            $table->unique(['run_id', 'sequence'], 'ah_events_sequence_unique');
            $table->index(['run_id', 'sequence'], 'ah_events_cursor_index');
            $table->index(['session_id', 'occurred_at'], 'ah_events_session_index');
            $table->index(['type', 'occurred_at'], 'ah_events_type_index');
            $table->index('occurred_at', 'ah_events_pruning_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clutch_events');
    }
};
