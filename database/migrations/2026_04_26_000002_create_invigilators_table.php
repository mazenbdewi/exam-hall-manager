<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invigilators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('staff_category', 30);
            $table->string('invigilation_role', 30);
            $table->unsignedInteger('max_assignments')->nullable();
            $table->unsignedInteger('max_assignments_per_day')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'phone'], 'invigilators_college_phone_unique');
            $table->index(['college_id', 'name'], 'invigilators_college_name_index');
            $table->index(['college_id', 'invigilation_role', 'is_active'], 'invigilators_role_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilators');
    }
};
