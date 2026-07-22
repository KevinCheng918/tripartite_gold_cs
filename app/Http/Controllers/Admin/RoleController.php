<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\AssignRolePermissionRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\PermissionMapResource;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\PermissionMapService;
use App\Services\RoleService;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    private RoleService $roleService;

    private PermissionMapService $permissionMapService;

    public function __construct(RoleService $roleService, PermissionMapService $permissionMapService)
    {
        $this->roleService = $roleService;
        $this->permissionMapService = $permissionMapService;
    }

    public function index()
    {
        return view('admin.roles.index');
    }

    public function ajaxList(Request $request)
    {
        $params = $request->only(['keyword', 'active_only', 'per_page']);

        $roles = $this->roleService->list($params);

        return RoleResource::collection($roles);
    }

    public function ajaxPermissionMap()
    {
        return new PermissionMapResource($this->permissionMapService->getGroupedKeywordsWithLabels());
    }

    public function ajaxStore(StoreRoleRequest $request)
    {
        $params = $request->validated();

        $role = $this->roleService->create($params);

        return new RoleResource($role);
    }

    public function ajaxUpdate(UpdateRoleRequest $request, Role $role)
    {
        $params = $request->validated();

        $role = $this->roleService->update($role, $params);

        return new RoleResource($role);
    }

    public function ajaxDelete(Role $role)
    {
        $this->roleService->delete($role);

        return response()->json(['message' => __('role.deleted')]);
    }

    public function ajaxAssignPermissions(AssignRolePermissionRequest $request, Role $role)
    {
        $params = $request->validated();

        $this->roleService->assignPermissions($role, $params['permissions']);

        return new RoleResource($role->load('permissions'));
    }
}
