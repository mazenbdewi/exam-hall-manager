<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_students')) {
            return;
        }

        Schema::table('exam_students', function (Blueprint $table): void {
            $table->index('student_number', 'exam_students_student_number_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exam_students')) {
            return;
        }

        Schema::table('exam_students', function (Blueprint $table): void {
            $table->dropIndex('exam_students_student_number_index');
        });
    }
};
