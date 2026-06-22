<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_schedule_drafts', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'exam_schedule_drafts_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_schedule_drafts', function (Blueprint $table): void {
            $table->dropIndex('exam_schedule_drafts_status_created_idx');
        });
    }
};
