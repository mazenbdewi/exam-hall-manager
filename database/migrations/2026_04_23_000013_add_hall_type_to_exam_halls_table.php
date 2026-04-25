<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_halls', function (Blueprint $table): void {
            $table->string('hall_type', 30)->after('capacity');
            $table->index('hall_type');
        });
    }

    public function down(): void
    {
        Schema::table('exam_halls', function (Blueprint $table): void {
            $table->dropIndex(['hall_type']);
            $table->dropColumn('hall_type');
        });
    }
};
