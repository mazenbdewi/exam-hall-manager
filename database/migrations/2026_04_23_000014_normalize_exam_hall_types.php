<?php

use App\Enums\ExamHallType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('exam_halls')
            ->whereNull('hall_type')
            ->orWhere('hall_type', '')
            ->update([
                'hall_type' => ExamHallType::Small->value,
            ]);
    }

    public function down(): void
    {
        DB::table('exam_halls')
            ->where('hall_type', ExamHallType::Small->value)
            ->update([
                'hall_type' => '',
            ]);
    }
};
