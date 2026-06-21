<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('system_settings', 'allow_normal_subjects_in_drawing_studios')) {
            return;
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->boolean('allow_normal_subjects_in_drawing_studios')
                ->default(false)
                ->after('database_backup_time');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('system_settings', 'allow_normal_subjects_in_drawing_studios')) {
            return;
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('allow_normal_subjects_in_drawing_studios');
        });
    }
};
