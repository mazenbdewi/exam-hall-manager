<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            if (! Schema::hasColumn('invigilators', 'eligible_roles')) {
                $table->json('eligible_roles')
                    ->nullable()
                    ->after('invigilation_role');
            }
        });

        DB::table('invigilators')
            ->select(['id', 'invigilation_role'])
            ->orderBy('id')
            ->get()
            ->each(function (object $invigilator): void {
                DB::table('invigilators')
                    ->where('id', $invigilator->id)
                    ->update([
                        'eligible_roles' => json_encode([(string) $invigilator->invigilation_role], JSON_UNESCAPED_UNICODE),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            if (Schema::hasColumn('invigilators', 'eligible_roles')) {
                $table->dropColumn('eligible_roles');
            }
        });
    }
};
