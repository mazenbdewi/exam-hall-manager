<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_exam_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_schedule_draft_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('college_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('college_name')->nullable();
            $table->string('department_name')->nullable();
            $table->string('academic_year');
            $table->string('semester');
            $table->string('title');
            $table->string('status')->default('fixed');
            $table->timestamp('fixed_at')->nullable();
            $table->foreignId('fixed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->json('snapshot_data');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'department_id', 'academic_year_id', 'semester_id'], 'fixed_exam_programs_scope_idx');
            $table->index(['status', 'fixed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_exam_programs');
    }
};
