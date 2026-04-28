<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_public_lookup_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('show_all_student_assignments')->default(false);
            $table->unsignedInteger('visibility_before_minutes')->default(60);
            $table->unsignedInteger('visibility_after_minutes')->default(180);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_public_lookup_settings');
    }
};
