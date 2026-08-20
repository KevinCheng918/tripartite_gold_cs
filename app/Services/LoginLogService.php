<?php

namespace App\Services;

use App\Repositories\LoginLogRepository;

/**
 * 登入紀錄 Service
 */
class LoginLogService
{
    private $loginLogRepository;

    public function __construct(LoginLogRepository $loginLogRepository)
    {
        $this->loginLogRepository = $loginLogRepository;
    }

    /**
     * 記錄登入（成功或失敗）
     *
     * @param array $params [user_id, account, ip, is_success, device, fail_reason]
     * @return \App\Models\LoginLog
     */
    public function record(array $params)
    {
        return $this->loginLogRepository->create([
            'user_id'     => $params['user_id'] ?? null,
            'account'     => $params['account'],
            'ip'          => $params['ip'],
            'is_success'  => $params['is_success'],
            'device'      => $params['device'] ?? null,
            'fail_reason' => $params['fail_reason'] ?? null,
        ]);
    }

    /**
     * 分頁查詢登入紀錄
     *
     * @param array $params [account, is_success, ip, start_date, end_date, per_page]
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function list(array $params)
    {
        $perPage = $params['per_page'] ?? config('constants.PAGINATION.DEFAULT');

        return $this->loginLogRepository->paginate($params, $perPage);
    }

    /**
     * 依帳號 ID 分頁查詢登入紀錄
     *
     * @param int $userId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listByUser($userId, $perPage = 10)
    {
        return $this->loginLogRepository->paginateByUserId($userId, $perPage);
    }
}
