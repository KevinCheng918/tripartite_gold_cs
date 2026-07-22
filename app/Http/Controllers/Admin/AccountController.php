<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AssignAccountRoleRequest;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private AccountService $accountService;

    public function __construct(AccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    public function index()
    {
        return view('admin.accounts.index');
    }

    public function ajaxList(Request $request)
    {
        $params = $request->only(['keyword', 'role_id', 'per_page']);

        $accounts = $this->accountService->list($params);

        return AccountResource::collection($accounts);
    }

    public function ajaxStore(StoreAccountRequest $request)
    {
        $params = $request->validated();

        $account = $this->accountService->create($params);

        return new AccountResource($account->load('roles'));
    }

    public function ajaxUpdate(UpdateAccountRequest $request, User $user)
    {
        $params = $request->validated();

        $account = $this->accountService->update($user, $params);

        return new AccountResource($account->load('roles'));
    }

    public function ajaxDelete(User $user)
    {
        $this->accountService->delete($user);

        return response()->json(['message' => __('account.deleted')]);
    }

    public function ajaxAssignRoles(AssignAccountRoleRequest $request, User $user)
    {
        $params = $request->validated();

        $this->accountService->assignRoles($user, $params['role_ids']);

        return new AccountResource($user->load('roles'));
    }
}
