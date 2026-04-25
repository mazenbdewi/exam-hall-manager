<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_halls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('location');
            $table->unsignedInteger('capacity');
            $table->string('priority', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'name']);
            $table->index('college_id');
            $table->index('priority');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_halls');
    }
};
