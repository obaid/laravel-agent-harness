<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clutch_artifacts', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('session_id', 40);
            $table->string('run_id', 40)->nullable();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind', 32);
            $table->string('mime_type')->nullable();

            $table->string('disk');
            $table->string('path', 1024);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('session_id')
                ->references('id')->on('clutch_sessions')
                ->cascadeOnDelete();

            $table->index(['session_id', 'created_at'], 'ah_artifacts_session_index');
            $table->index(['run_id', 'created_at'], 'ah_artifacts_run_index');
            $table->index('created_at', 'ah_artifacts_pruning_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clutch_artifacts');
    }
};
