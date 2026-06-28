<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('invigilator_distribution_draft_assignments');
        Schema::dropIfExists('invigilator_distribution_drafts');

        Schema::create('invigilator_distribution_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->date('exam_date_from')->nullable();
            $table->date('exam_date_to')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['college_id', 'exam_date_from', 'exam_date_to'], 'invigilation_drafts_scope_index');
            $table->index(['status', 'created_at'], 'invigilation_drafts_status_created_index');
        });

        Schema::create('invigilator_distribution_draft_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id');
            $table->foreignId('college_id')->nullable();
            $table->foreignId('invigilator_id')->nullable();
            $table->foreignId('exam_hall_id')->nullable();
            $table->date('exam_date');
            $table->time('start_time');
            $table->string('invigilation_role', 30);
            $table->unsignedInteger('current_duties_count')->default(0);
            $table->unsignedInteger('proposed_duties_count')->default(0);
            $table->integer('difference')->default(0);
            $table->json('relaxed_constraints_json')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['draft_id', 'exam_date', 'start_time'], 'invigilation_draft_assignments_slot_index');
            $table->index(['invigilator_id', 'exam_date', 'start_time'], 'invigilation_draft_assignments_conflict_index');

            $table->foreign('draft_id', 'invig_draft_asg_draft_fk')->references('id')->on('invigilator_distribution_drafts')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('college_id', 'invig_draft_asg_college_fk')->references('id')->on('colleges')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('invigilator_id', 'invig_draft_asg_invigilator_fk')->references('id')->on('invigilators')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('exam_hall_id', 'invig_draft_asg_hall_fk')->references('id')->on('exam_halls')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilator_distribution_draft_assignments');
        Schema::dropIfExists('invigilator_distribution_drafts');
    }
};
