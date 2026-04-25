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

        if (! Schema::hasColumn('subject_exam_offerings', 'exam_program_file')) {
            return;
        }

        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            $table->dropColumn('exam_program_file');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('subject_exam_offerings')) {
            return;
        }

        if (Schema::hasColumn('subject_exam_offerings', 'exam_program_file')) {
            return;
        }

        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            $table->string('exam_program_file')->nullable()->after('exam_start_time');
        });
    }
};
