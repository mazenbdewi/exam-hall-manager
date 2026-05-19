<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hall_settings', function (Blueprint $table): void {
            $table->foreignId('college_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unique('college_id');
        });

        $globalSettings = DB::table('hall_settings')
            ->whereNull('college_id')
            ->first();

        $defaults = [
            'large_hall_min_capacity' => (int) ($globalSettings->large_hall_min_capacity ?? 100),
            'amphitheater_min_capacity' => (int) ($globalSettings->amphitheater_min_capacity ?? 200),
        ];

        $collegeIds = DB::table('colleges')->orderBy('id')->pluck('id');
        $firstCollegeId = $collegeIds->first();

        if ($globalSettings && $firstCollegeId) {
            DB::table('hall_settings')
                ->where('id', $globalSettings->id)
                ->update([
                    'college_id' => $firstCollegeId,
                    'updated_at' => now(),
                ]);
        }

        foreach ($collegeIds as $collegeId) {
            $exists = DB::table('hall_settings')
                ->where('college_id', $collegeId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('hall_settings')->insert([
                'college_id' => $collegeId,
                ...$defaults,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('hall_settings')
            ->whereNull('college_id')
            ->delete();
    }

    public function down(): void
    {
        Schema::table('hall_settings', function (Blueprint $table): void {
            $table->dropForeign(['college_id']);
            $table->dropUnique(['college_id']);
            $table->dropColumn('college_id');
        });
    }
};
