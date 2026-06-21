<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subjects', 'is_drawing_subject')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->boolean('is_drawing_subject')
                    ->default(false)
                    ->after('is_active');
            });
        }

        if (! Schema::hasIndex('subjects', ['is_drawing_subject'])) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->index('is_drawing_subject');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('subjects', ['is_drawing_subject'])) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->dropIndex(['is_drawing_subject']);
            });
        }

        if (Schema::hasColumn('subjects', 'is_drawing_subject')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->dropColumn('is_drawing_subject');
            });
        }
    }
};
