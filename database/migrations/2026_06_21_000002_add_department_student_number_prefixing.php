<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colleges', function (Blueprint $table): void {
            $table->boolean('enable_department_student_number_prefix')
                ->default(false)
                ->after('is_active');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->string('student_number_prefix', 20)
                ->nullable()
                ->after('code');

            $table->unique(['college_id', 'student_number_prefix'], 'departments_college_student_prefix_unique');
        });

        Schema::table('subject_exam_roster_students', function (Blueprint $table): void {
            $table->string('original_student_number')
                ->nullable()
                ->after('student_number');

            $table->index('original_student_number');
        });
    }

    public function down(): void
    {
        Schema::table('subject_exam_roster_students', function (Blueprint $table): void {
            $table->dropIndex(['original_student_number']);
            $table->dropColumn('original_student_number');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_college_student_prefix_unique');
            $table->dropColumn('student_number_prefix');
        });

        Schema::table('colleges', function (Blueprint $table): void {
            $table->dropColumn('enable_department_student_number_prefix');
        });
    }
};
