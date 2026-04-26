<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invigilator_unassigned_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->foreignId('exam_hall_id')->constrained('exam_halls')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('invigilation_role', 30);
            $table->unsignedInteger('required_count');
            $table->unsignedInteger('assigned_count')->default(0);
            $table->unsignedInteger('shortage_count')->default(0);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['college_id', 'exam_date', 'start_time'], 'invigilator_shortages_slot_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilator_unassigned_requirements');
    }
};
