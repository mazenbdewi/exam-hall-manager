<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            $table->unsignedTinyInteger('workload_reduction_percentage')
                ->default(0)
                ->after('max_assignments_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            $table->dropColumn('workload_reduction_percentage');
        });
    }
};
