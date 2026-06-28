<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hall_assignments', function (Blueprint $table): void {
            $table->index(['college_id', 'exam_date', 'assigned_students_count'], 'hall_asg_college_date_students_idx');
        });

        Schema::table('invigilator_assignments', function (Blueprint $table): void {
            $table->index(['college_id', 'exam_date', 'assignment_status'], 'invig_asg_college_date_status_idx');
            $table->index(['college_id', 'exam_date', 'start_time', 'exam_hall_id', 'invigilation_role'], 'invig_asg_hall_role_idx');
            $table->index(['invigilator_id', 'exam_date'], 'invig_asg_invigilator_date_idx');
        });

        Schema::table('invigilator_unassigned_requirements', function (Blueprint $table): void {
            $table->index(['college_id', 'exam_date', 'invigilation_role'], 'invig_short_college_date_role_idx');
        });

        Schema::table('student_distribution_runs', function (Blueprint $table): void {
            $table->index(['college_id', 'status', 'executed_at'], 'student_dist_college_status_exec_idx');
        });

        Schema::table('student_distribution_run_issues', function (Blueprint $table): void {
            $table->index(['student_distribution_run_id', 'issue_type'], 'sdr_issues_run_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('student_distribution_run_issues', function (Blueprint $table): void {
            $table->dropIndex('sdr_issues_run_type_idx');
        });

        Schema::table('student_distribution_runs', function (Blueprint $table): void {
            $table->dropIndex('student_dist_college_status_exec_idx');
        });

        Schema::table('invigilator_unassigned_requirements', function (Blueprint $table): void {
            $table->dropIndex('invig_short_college_date_role_idx');
        });

        Schema::table('invigilator_assignments', function (Blueprint $table): void {
            $table->dropIndex('invig_asg_college_date_status_idx');
            $table->dropIndex('invig_asg_hall_role_idx');
            $table->dropIndex('invig_asg_invigilator_date_idx');
        });

        Schema::table('hall_assignments', function (Blueprint $table): void {
            $table->dropIndex('hall_asg_college_date_students_idx');
        });
    }
};
