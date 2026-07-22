<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Services\PermissionMapService;
use App\Services\PermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SetPermissionSeeder extends Seeder
{
    /**
     * Sync the admin role's permissions to the full current permission-keyword catalog.
     * Re-run this any time a new permission keyword is registered in config/permissionMap.php.
     *
     * @return void
     */
    public function run()
    {
        $role = Role::query()->where('name', Role::ADMIN_ROLE_NAME)->first();

        if (!$role) {
            $this->command->warn('Admin role not found — run CreateAdminSeeder first.');

            return;
        }

        $keywords = app(PermissionMapService::class)->getAllKeywords();

        DB::transaction(function () use ($role, $keywords) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();

            if (empty($keywords)) {
                return;
            }

            $rows = array_map(function ($keyword) use ($role) {
                return [
                    'role_id' => $role->id,
                    'permission_keyword' => $keyword,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $keywords);

            DB::table('role_permissions')->insert($rows);
        });

        app(PermissionService::class)->forgetRoleCache($role);
    }
}
