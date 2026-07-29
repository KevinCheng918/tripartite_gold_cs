<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 建立初始管理員帳號
 *
 * 參考主系統 tripartite_gold 的 CreateAdminSeeder 模式：
 * - 帳號不存在 → 新建（level=ADMIN）
 * - 帳號已存在 → 重置密碼
 */
class CreateAdminSeeder extends Seeder
{
    /** @var string 預設管理員帳號 */
    private $account = '';

    /** @var string 預設管理員密碼 */
    private $password = '';

    /**
     * @return void
     */
    public function run()
    {
        $this->account = config('admin.account', 'admin');
        $this->password = config('admin.password', 'qwqw1212');

        DB::transaction(function () {
            $user = User::query()
                ->where('account', $this->account)
                ->first();

            if (blank($user)) {
                User::query()->create([
                    'account' => $this->account,
                    'password' => $this->password,
                    'nickname' => 'Admin',
                    'status' => config('constants.USER.STATUS.NORMAL'),
                    'level' => config('constants.USER.LEVEL.ADMIN'),
                ]);

                $this->command->info("成功新增：{$this->account} // {$this->password}。");
            } else {
                $user->update(['password' => $this->password]);

                $this->command->error("已重置管理者：{$this->account} // {$this->password}。");
            }
        });
    }
}
