<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('colleges', 'allow_normal_subjects_in_drawing_studios')) {
            Schema::table('colleges', function (Blueprint $table): void {
                $table->boolean('allow_normal_subjects_in_drawing_studios')
                    ->default(false)
                    ->after('enable_department_student_number_prefix');
            });
        }

        if (Schema::hasColumn('system_settings', 'allow_normal_subjects_in_drawing_studios')) {
            $legacyValue = (bool) (SystemSetting::query()->value('allow_normal_subjects_in_drawing_studios') ?? false);

            if ($legacyValue) {
                DB::table('colleges')->update([
                    'allow_normal_subjects_in_drawing_studios' => true,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('colleges', 'allow_normal_subjects_in_drawing_studios')) {
            return;
        }

        Schema::table('colleges', function (Blueprint $table): void {
            $table->dropColumn('allow_normal_subjects_in_drawing_studios');
        });
    }
};
