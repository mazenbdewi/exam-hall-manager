<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_distribution_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 20);
            $table->unsignedInteger('total_offerings')->default(0);
            $table->unsignedInteger('total_slots')->default(0);
            $table->unsignedInteger('total_students')->default(0);
            $table->unsignedInteger('distributed_students')->default(0);
            $table->unsignedInteger('unassigned_students')->default(0);
            $table->unsignedInteger('total_capacity')->default(0);
            $table->unsignedInteger('used_halls')->default(0);
            $table->unsignedInteger('capacity_shortage')->default(0);
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('executed_at')->nullable();
            $table->json('summary_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['college_id', 'from_date', 'to_date'], 'student_distribution_runs_scope_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_distribution_runs');
    }
};
