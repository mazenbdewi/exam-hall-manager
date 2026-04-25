<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_assignment_subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_assignment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('subject_exam_offering_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('assigned_students_count')->default(0);
            $table->timestamps();

            $table->unique(['hall_assignment_id', 'subject_exam_offering_id'], 'hall_assignment_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_assignment_subjects');
    }
};
