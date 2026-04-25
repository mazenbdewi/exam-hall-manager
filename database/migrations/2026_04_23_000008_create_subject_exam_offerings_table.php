<?php

use App\Enums\ExamOfferingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subject_exam_offerings')) {
            Schema::table('subject_exam_offerings', function (Blueprint $table): void {
                $table->index(['subject_id', 'academic_year_id', 'semester_id'], 'seo_subject_year_semester_idx');
                $table->index('status');
            });

            return;
        }

        Schema::create('subject_exam_offerings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('exam_date');
            $table->time('exam_start_time');
            $table->text('notes')->nullable();
            $table->string('status')->default(ExamOfferingStatus::Draft->value);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_id', 'academic_year_id', 'semester_id'], 'seo_subject_year_semester_idx');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_exam_offerings');
    }
};
