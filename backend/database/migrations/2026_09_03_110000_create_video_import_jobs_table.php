<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('video_import_jobs')) {
            return;
        }

        Schema::create('video_import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('category_slug', 128);
            $table->string('mode', 32)->default('import');
            $table->unsignedInteger('minimum_count');
            $table->unsignedInteger('target_count');
            $table->unsignedInteger('max_pages')->default(40);
            $table->string('status', 32)->default('queued');
            $table->string('stage', 64)->default('queued');
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedBigInteger('process_id')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('log_path', 1024)->nullable();
            $table->text('message')->nullable();
            $table->json('result_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['source', 'category_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_import_jobs');
    }
};
