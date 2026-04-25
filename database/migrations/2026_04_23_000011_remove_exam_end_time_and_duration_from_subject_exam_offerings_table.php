<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subject_exam_offerings')) {
            return;
        }

        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            if (Schema::hasColumn('subject_exam_offerings', 'exam_end_time')) {
                $table->dropColumn('exam_end_time');
            }

            if (Schema::hasColumn('subject_exam_offerings', 'exam_duration_minutes')) {
                $table->dropColumn('exam_duration_minutes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('subject_exam_offerings')) {
            return;
        }

        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            if (! Schema::hasColumn('subject_exam_offerings', 'exam_end_time')) {
                $table->time('exam_end_time')->nullable()->after('exam_start_time');
            }

            if (! Schema::hasColumn('subject_exam_offerings', 'exam_duration_minutes')) {
                $table->unsignedInteger('exam_duration_minutes')->nullable()->after('exam_end_time');
            }
        });
    }
};
