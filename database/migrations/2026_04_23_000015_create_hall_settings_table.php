<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('large_hall_min_capacity')->default(100);
            $table->unsignedInteger('amphitheater_min_capacity')->default(200);
            $table->timestamps();
        });

        DB::table('hall_settings')->insert([
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_settings');
    }
};
