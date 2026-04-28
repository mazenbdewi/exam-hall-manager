<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('security_pin_hash')->nullable()->after('password');
            $table->boolean('security_pin_enabled')->default(false)->after('security_pin_hash');
            $table->timestamp('security_pin_set_at')->nullable()->after('security_pin_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'security_pin_hash',
                'security_pin_enabled',
                'security_pin_set_at',
            ]);
        });
    }
};
