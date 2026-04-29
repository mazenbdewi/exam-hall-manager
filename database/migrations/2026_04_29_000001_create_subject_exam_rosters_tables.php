<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_exam_rosters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('study_level_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('source', 30)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('college_id');
            $table->index('department_id');
            $table->index('subject_id');
            $table->index('academic_year_id');
            $table->index('semester_id');
            $table->index('status');
        });

        Schema::create('subject_exam_roster_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_exam_roster_id')
                ->constrained('subject_exam_rosters')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('student_number');
            $table->string('full_name');
            $table->string('student_type', 30)->default('regular');
            $table->boolean('is_eligible')->default(true);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subject_exam_roster_id', 'student_number'], 'subject_exam_roster_student_unique');
            $table->index('student_number');
            $table->index('student_type');
            $table->index('is_eligible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_exam_roster_students');
        Schema::dropIfExists('subject_exam_rosters');
    }
};
