<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CreateAdminSeeder;
use Database\Seeders\SetPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_access_protected_route()
    {
        $this->seed(CreateAdminSeeder::class);
        $this->seed(SetPermissionSeeder::class);

        $response = $this->post('/login', [
            'email' => config('admin.email'),
            'password' => config('admin.password'),
        ]);

        $response->assertRedirect(route('admin.accounts.index'));
        $this->assertAuthenticated();

        $this->get('/admin/accounts')->assertStatus(200);
    }

    public function test_user_without_permission_gets_403()
    {
        $role = Role::create(['name' => 'viewer', 'display_name' => 'Viewer', 'is_active' => true]);
        $role->permissions()->create(['permission_keyword' => 'account.view']);

        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/admin/accounts/ajax-list')->assertStatus(200);
        $this->actingAs($user)->deleteJson('/admin/accounts/ajax-delete/' . $user->id)->assertStatus(403);
    }
}
