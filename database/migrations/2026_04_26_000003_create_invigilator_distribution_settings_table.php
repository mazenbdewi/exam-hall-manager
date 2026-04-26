<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invigilator_distribution_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('default_max_assignments_per_invigilator')->default(3);
            $table->boolean('allow_multiple_assignments_per_day')->default(false);
            $table->unsignedInteger('max_assignments_per_day')->default(1);
            $table->string('distribution_pattern', 30)->default('balanced');
            $table->string('day_preference', 30)->default('balanced');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilator_distribution_settings');
    }
};
