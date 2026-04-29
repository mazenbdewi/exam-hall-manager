<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            if (! Schema::hasColumn('subjects', 'is_shared_subject')) {
                $table->boolean('is_shared_subject')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('subjects', 'shared_subject_scheduling_mode')) {
                $table->string('shared_subject_scheduling_mode', 40)->default('auto')->after('is_shared_subject');
            }

            $table->index(['college_id', 'is_shared_subject'], 'subjects_college_shared_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropIndex('subjects_college_shared_idx');
            $table->dropColumn(['is_shared_subject', 'shared_subject_scheduling_mode']);
        });
    }
};
