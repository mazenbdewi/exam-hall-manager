<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_exam_offering_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('student_number');
            $table->string('full_name');
            $table->string('student_type');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['subject_exam_offering_id', 'student_number'], 'exam_students_offering_student_number_unique');
            $table->index('student_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_students');
    }
};
