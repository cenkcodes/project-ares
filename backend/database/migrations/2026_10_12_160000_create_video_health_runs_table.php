<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_health_runs', function (Blueprint $table): void {
            $table->id();
            $table->enum('provider', ['all', 'eporner', 'xvideos', 'xnxx', 'redtube']);
            $table->enum('mode', ['audit-only', 'audit-deactivate'])->default('audit-only');
            $table->enum('trigger', ['manual', 'scheduled']);
            $table->string('schedule_key')->nullable()->unique();
            $table->enum('status', ['deferred', 'queued', 'launching', 'running', 'deactivating', 'completed', 'failed', 'aborted'])->default('queued');
            $table->string('stage', 64)->default('queued');
            $table->string('classifier_version', 64);
            $table->string('config_sha256', 64);

            // Inline CHECKs work on both SQLite and PostgreSQL; unsigned alone does not.
            foreach (['selected_count', 'scanned_count', 'pass_count', 'broken_count', 'review_count', 'unverifiable_count', 'deactivated_count'] as $counter) {
                $constraint = $counter.' >= 0';
                if ($counter === 'deactivated_count') {
                    $constraint .= ' AND deactivated_count <= broken_count';
                }
                $table->rawColumn($counter, 'bigint CHECK ('.$constraint.')')->default(0);
            }

            $table->unsignedBigInteger('process_id')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('artifact_directory', 1024)->nullable();
            $table->string('log_path', 1024)->nullable();
            $table->string('manifest_sha256', 64)->nullable();
            $table->text('message')->nullable();
            $table->json('result_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['provider', 'created_at']);
            $table->index('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_health_runs');
    }
};
