<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_shared_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['subject_id', 'department_id'], 'subject_shared_departments_unique');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_shared_departments');
    }
};
