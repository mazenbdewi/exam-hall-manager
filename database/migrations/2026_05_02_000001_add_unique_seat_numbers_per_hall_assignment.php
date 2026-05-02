<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('exam_student_hall_assignments')
            ->select('hall_assignment_id')
            ->distinct()
            ->orderBy('hall_assignment_id')
            ->get()
            ->each(function (object $assignment): void {
                $seatNumber = 1;

                DB::table('exam_student_hall_assignments')
                    ->where('hall_assignment_id', $assignment->hall_assignment_id)
                    ->orderBy('id')
                    ->get(['id'])
                    ->each(function (object $studentAssignment) use (&$seatNumber): void {
                        DB::table('exam_student_hall_assignments')
                            ->whereKey($studentAssignment->id)
                            ->update(['seat_number' => $seatNumber++]);
                    });
            });

        Schema::table('exam_student_hall_assignments', function (Blueprint $table): void {
            $table->unique(['hall_assignment_id', 'seat_number'], 'student_hall_assignment_seat_unique');
        });
    }

    public function down(): void
    {
        Schema::table('exam_student_hall_assignments', function (Blueprint $table): void {
            $table->dropUnique('student_hall_assignment_seat_unique');
        });
    }
};
