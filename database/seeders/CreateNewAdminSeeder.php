<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateNewAdminSeeder extends Seeder
{
    /**
     * Seed an additional admin account (on top of CreateAdminSeeder's initial admin).
     *
     * @return void
     */
    public function run()
    {
        if (blank(config('admin.new_admin_password'))) {
            $this->command->error('NEW_ADMIN_PASSWORD is not set in .env — aborting.');

            return;
        }

        DB::transaction(function () {
            $role = Role::query()->firstOrCreate(
                ['name' => Role::ADMIN_ROLE_NAME],
                ['display_name' => 'Administrator', 'is_active' => true, 'sort' => 0]
            );

            $user = User::query()->firstOrCreate(
                ['email' => config('admin.new_admin_email')],
                [
                    'name' => 'Admin',
                    'password' => Hash::make(config('admin.new_admin_password')),
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}
