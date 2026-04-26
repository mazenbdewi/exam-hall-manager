<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invigilator_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('subject_exam_offering_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->foreignId('exam_hall_id')->constrained('exam_halls')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('invigilator_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('invigilation_role', 30);
            $table->string('assignment_status', 30)->default('assigned');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['exam_hall_id', 'exam_date', 'start_time', 'invigilator_id'], 'invigilator_assignments_hall_slot_invigilator_unique');
            $table->index(['college_id', 'exam_date', 'start_time'], 'invigilator_assignments_slot_index');
            $table->index(['invigilator_id', 'exam_date', 'start_time'], 'invigilator_assignments_conflict_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilator_assignments');
    }
};
