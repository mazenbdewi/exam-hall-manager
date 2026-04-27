<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invigilator_distribution_settings', function (Blueprint $table): void {
            $table->boolean('allow_role_fallback')
                ->default(false)
                ->after('allow_multiple_assignments_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('invigilator_distribution_settings', function (Blueprint $table): void {
            $table->dropColumn('allow_role_fallback');
        });
    }
};
