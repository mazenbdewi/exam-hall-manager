<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            $table->boolean('allow_multiple_assignments_per_day')
                ->nullable()
                ->after('max_assignments_per_day');
            $table->string('day_preference', 30)
                ->nullable()
                ->after('allow_multiple_assignments_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_multiple_assignments_per_day',
                'day_preference',
            ]);
        });
    }
};
