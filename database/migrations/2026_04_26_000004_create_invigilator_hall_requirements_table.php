<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invigilator_hall_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('hall_type', 30);
            $table->unsignedInteger('hall_head_count')->default(1);
            $table->unsignedInteger('secretary_count')->default(1);
            $table->unsignedInteger('regular_count')->default(1);
            $table->unsignedInteger('reserve_count')->default(0);
            $table->timestamps();

            $table->unique(['college_id', 'hall_type'], 'invigilator_requirements_college_hall_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilator_hall_requirements');
    }
};
