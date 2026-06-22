<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            if (! Schema::hasColumn('subject_exam_offerings', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->after('exam_start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subject_exam_offerings', function (Blueprint $table): void {
            if (Schema::hasColumn('subject_exam_offerings', 'is_pinned')) {
                $table->dropColumn('is_pinned');
            }
        });
    }
};
