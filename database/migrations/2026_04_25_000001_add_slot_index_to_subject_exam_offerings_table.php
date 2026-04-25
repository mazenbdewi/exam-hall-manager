<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            $table->index(['exam_date', 'exam_start_time'], 'seo_exam_slot_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            $table->dropIndex('seo_exam_slot_idx');
        });
    }
};
