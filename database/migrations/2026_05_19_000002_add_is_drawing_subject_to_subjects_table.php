<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->boolean('is_drawing_subject')
                ->default(false)
                ->after('is_active');
            $table->index('is_drawing_subject');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropIndex(['is_drawing_subject']);
            $table->dropColumn('is_drawing_subject');
        });
    }
};
