<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_import_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('start_page')
                ->default(0)
                ->after('max_pages');
        });
    }

    public function down(): void
    {
        Schema::table('video_import_jobs', function (Blueprint $table): void {
            $table->dropColumn('start_page');
        });
    }
};