<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_halls', function (Blueprint $table): void {
            $table->boolean('is_drawing_studio')
                ->default(false)
                ->after('hall_type');
            $table->index('is_drawing_studio');
        });
    }

    public function down(): void
    {
        Schema::table('exam_halls', function (Blueprint $table): void {
            $table->dropIndex(['is_drawing_studio']);
            $table->dropColumn('is_drawing_studio');
        });
    }
};
