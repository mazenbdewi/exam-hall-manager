<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\User;
use App\Support\RoleNames;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate(RoleNames::SUPER_ADMIN, 'web');
        Role::findOrCreate(RoleNames::ADMIN, 'web');

        $colleges = College::query()->get()->keyBy('code');

        foreach (DemoSeedData::users() as $userData) {
            $collegeId = $userData['college_code']
                ? $colleges->get($userData['college_code'])?->id
                : null;

            $user = User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make($userData['password']),
                    'email_verified_at' => now(),
                    'college_id' => $collegeId,
                ],
            );

            $user->syncRoles([$userData['role']]);
        }
    }
}
