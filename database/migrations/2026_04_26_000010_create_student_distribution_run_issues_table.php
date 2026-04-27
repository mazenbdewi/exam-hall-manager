<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_distribution_run_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_distribution_run_id');
            $table->date('exam_date')->nullable();
            $table->time('start_time')->nullable();
            $table->foreignId('subject_exam_offering_id')->nullable();
            $table->string('issue_type', 50);
            $table->text('message');
            $table->unsignedInteger('affected_students_count')->default(0);
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['exam_date', 'start_time'], 'student_distribution_run_issues_slot_index');
            $table->index('issue_type');
            $table->foreign('student_distribution_run_id', 'sdr_issues_run_fk')
                ->references('id')
                ->on('student_distribution_runs')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign('subject_exam_offering_id', 'sdr_issues_offering_fk')
                ->references('id')
                ->on('subject_exam_offerings')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_distribution_run_issues');
    }
};
