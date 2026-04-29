<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_schedule_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faculty_id')->constrained('colleges')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('generated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('settings_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();

            $table->index(['faculty_id', 'academic_year_id', 'semester_id'], 'exam_schedule_drafts_scope_idx');
            $table->index('status');
        });

        Schema::create('exam_schedule_draft_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_schedule_draft_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('subject_exam_offering_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->date('exam_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('student_count')->default(0);
            $table->boolean('is_shared_subject')->default(false);
            $table->string('shared_group_key')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->text('conflict_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['exam_schedule_draft_id', 'exam_date', 'start_time'], 'exam_schedule_draft_items_slot_idx');
            $table->index(['subject_id', 'department_id'], 'exam_schedule_draft_items_subject_dept_idx');
            $table->index('status');
        });

        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            if (! Schema::hasColumn('subject_exam_offerings', 'exam_schedule_draft_id')) {
                $table->foreignId('exam_schedule_draft_id')
                    ->nullable()
                    ->after('semester_id')
                    ->constrained('exam_schedule_drafts')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            if (Schema::hasColumn('subject_exam_offerings', 'exam_schedule_draft_id')) {
                $table->dropConstrainedForeignId('exam_schedule_draft_id');
            }
        });

        Schema::dropIfExists('exam_schedule_draft_items');
        Schema::dropIfExists('exam_schedule_drafts');
    }
};
