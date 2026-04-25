<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_student_hall_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_student_id')->constrained('exam_students')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('hall_assignment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('subject_exam_offering_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('seat_number')->nullable();
            $table->timestamps();

            $table->unique('exam_student_id');
            $table->index(['hall_assignment_id', 'subject_exam_offering_id'], 'student_hall_assignment_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_student_hall_assignments');
    }
};
