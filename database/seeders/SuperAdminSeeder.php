<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate(RoleNames::SUPER_ADMIN, 'web');

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin'),
                'college_id' => null,
            ],
        );

        $user->syncRoles([RoleNames::SUPER_ADMIN]);
    }
}
