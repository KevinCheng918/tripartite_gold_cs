<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateAdminSeeder extends Seeder
{
    /**
     * Seed the initial admin role and admin account.
     *
     * @return void
     */
    public function run()
    {
        DB::transaction(function () {
            $role = Role::query()->firstOrCreate(
                ['name' => Role::ADMIN_ROLE_NAME],
                ['display_name' => 'Administrator', 'is_active' => true, 'sort' => 0]
            );

            $user = User::query()->firstOrCreate(
                ['email' => config('admin.email')],
                [
                    'name' => 'Admin',
                    'password' => Hash::make(config('admin.password')),
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}
