<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $nullPhonesCount = DB::table('invigilators')->whereNull('phone')->count();

        if ($nullPhonesCount > 0 && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Cannot make invigilators.phone required while existing invigilators have null phone values. Please fill real phone numbers first.');
        }

        if ($nullPhonesCount > 0) {
            DB::table('invigilators')
                ->whereNull('phone')
                ->orderBy('id')
                ->get(['id'])
                ->each(function (object $invigilator): void {
                    // Local/demo placeholder to let development databases migrate cleanly.
                    // Production data should be corrected with real phone numbers before this migration.
                    DB::table('invigilators')
                        ->where('id', $invigilator->id)
                        ->update([
                            'phone' => '0999'.str_pad((string) $invigilator->id, 6, '0', STR_PAD_LEFT),
                        ]);
                });
        }

        Schema::table('invigilators', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invigilators', function (Blueprint $table): void {
            $table->string('phone')->nullable()->change();
        });
    }
};
