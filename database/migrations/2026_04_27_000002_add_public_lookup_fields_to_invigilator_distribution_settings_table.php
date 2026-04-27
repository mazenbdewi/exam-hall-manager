<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invigilator_distribution_settings', function (Blueprint $table): void {
            $table->boolean('show_all_invigilator_assignments')
                ->default(false)
                ->after('day_preference');
            $table->unsignedInteger('visibility_before_minutes')
                ->default(60)
                ->after('show_all_invigilator_assignments');
            $table->unsignedInteger('visibility_after_minutes')
                ->default(180)
                ->after('visibility_before_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('invigilator_distribution_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'show_all_invigilator_assignments',
                'visibility_before_minutes',
                'visibility_after_minutes',
            ]);
        });
    }
};
