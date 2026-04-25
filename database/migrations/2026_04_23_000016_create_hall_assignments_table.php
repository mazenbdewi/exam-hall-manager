<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_hall_id')->constrained('exam_halls')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('exam_date');
            $table->time('exam_start_time');
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('total_capacity');
            $table->unsignedInteger('assigned_students_count')->default(0);
            $table->unsignedInteger('remaining_capacity')->default(0);
            $table->timestamps();

            $table->unique(['exam_hall_id', 'exam_date', 'exam_start_time'], 'hall_assignments_slot_unique');
            $table->index(['college_id', 'exam_date', 'exam_start_time'], 'hall_assignments_slot_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_assignments');
    }
};
