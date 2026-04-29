<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            if (! Schema::hasColumn('subjects', 'is_core_subject')) {
                $table->boolean('is_core_subject')->default(false)->after('shared_subject_scheduling_mode');
            }

            if (! Schema::hasColumn('subjects', 'preferred_exam_period')) {
                $table->string('preferred_exam_period', 30)->default('none')->after('is_core_subject');
            }

            if (! Schema::hasColumn('subjects', 'core_subject_priority')) {
                $table->string('core_subject_priority', 40)->default('preference')->after('preferred_exam_period');
            }
        });

        Schema::table('exam_schedule_draft_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('exam_schedule_draft_items', 'source_roster_id')) {
                $table->foreignId('source_roster_id')
                    ->nullable()
                    ->after('exam_schedule_draft_id')
                    ->constrained('subject_exam_rosters')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('exam_schedule_draft_items', 'period_type')) {
                $table->string('period_type', 30)->nullable()->after('end_time');
            }

            if (! Schema::hasColumn('exam_schedule_draft_items', 'regular_count')) {
                $table->unsignedInteger('regular_count')->default(0)->after('student_count');
            }

            if (! Schema::hasColumn('exam_schedule_draft_items', 'carry_count')) {
                $table->unsignedInteger('carry_count')->default(0)->after('regular_count');
            }

            if (! Schema::hasColumn('exam_schedule_draft_items', 'is_core_subject')) {
                $table->boolean('is_core_subject')->default(false)->after('is_shared_subject');
            }

            $table->index('source_roster_id');
        });
    }

    public function down(): void
    {
        Schema::table('exam_schedule_draft_items', function (Blueprint $table): void {
            $table->dropIndex(['source_roster_id']);
            $table->dropConstrainedForeignId('source_roster_id');
            $table->dropColumn(['period_type', 'regular_count', 'carry_count', 'is_core_subject']);
        });

        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropColumn(['is_core_subject', 'preferred_exam_period', 'core_subject_priority']);
        });
    }
};
