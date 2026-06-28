<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_distribution_runs', function (Blueprint $table): void {
            $table->string('status', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_distribution_runs', function (Blueprint $table): void {
            $table->string('status', 20)->change();
        });
    }
};
