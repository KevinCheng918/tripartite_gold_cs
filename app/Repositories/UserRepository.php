<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 使用者 Repository
 *
 * 負責 user 表及 user_permissions 表的所有 DB 操作。
 */
class UserRepository
{
    /*
     * 列表查詢欄位。
     *
     * ⚠ `telegram_dm_ready` 是帳號管理列表要顯示「有沒有私訊過機器人」用的。
     * 少了它那一欄永遠顯示「未綁定」而且不會報錯 —— 這個專案為「select 漏欄位」
     * 的同一類問題修過好幾次（見 bugfix/2026-09-30-on-duty-reply-time）。
     */
    private const LIST_COLUMNS = ['id', 'account', 'nickname', 'telegram_nickname', 'telegram_username', 'telegram_user_id', 'telegram_dm_ready', 'status', 'level', 'project_ids', 'hired_at', 'equipments', 'created_at'];

    /**
     * 依條件分頁查詢使用者
     *
     * @param array $filters 篩選條件（account, nickname, status, level）
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($filters = [], $perPage = 20)
    {
        $query = User::query()->select(self::LIST_COLUMNS)->with('permissions');

        if (filled($filters['account'] ?? null)) {
            $query->where('account', 'like', "%{$filters['account']}%");
        }

        if (filled($filters['nickname'] ?? null)) {
            $query->where('nickname', 'like', "%{$filters['nickname']}%");
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int) $filters['status']);
        }

        if (isset($filters['level']) && $filters['level'] !== '' && $filters['level'] !== null) {
            $query->where('level', (int) $filters['level']);
        }

        if (filled($filters['exclude_id'] ?? null)) {
            $query->where('id', '!=', (int) $filters['exclude_id']);
        }

        if (filled($filters['min_level'] ?? null)) {
            $query->where('level', '>=', (int) $filters['min_level']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 統計客服帳號各狀態數量（排除管理者）
     *
     * @return array
     */
    public function countByStatus()
    {
        $statusRows = User::query()
            ->selectRaw('status, COUNT(*) as count')
            ->where('level', '!=', config('constants.USER.LEVEL.ADMIN'))
            ->groupBy('status')
            ->get();

        $normal = 0;
        $lock = 0;
        $deactivate = 0;

        foreach ($statusRows as $row) {
            if ((int) $row->status === config('constants.USER.STATUS.NORMAL')) {
                $normal = $row->count;
            } elseif ((int) $row->status === config('constants.USER.STATUS.LOCK')) {
                $lock = $row->count;
            } elseif ((int) $row->status === config('constants.USER.STATUS.DEACTIVATE')) {
                $deactivate = $row->count;
            }
        }

        // 按身份統計
        $levelRows = User::query()
            ->selectRaw('level, COUNT(*) as count')
            ->where('level', '!=', config('constants.USER.LEVEL.ADMIN'))
            ->groupBy('level')
            ->get();

        $byLevel = [];
        foreach ($levelRows as $row) {
            $byLevel[(int) $row->level] = $row->count;
        }

        return [
            'normal'     => $normal,
            'lock'       => $lock,
            'deactivate' => $deactivate,
            'total'      => $normal + $lock + $deactivate,
            'by_level'   => $byLevel,
        ];
    }

    /**
     * 依 ID 查詢使用者
     *
     * @param int $id
     * @return User|null
     */
    public function find($id)
    {
        return User::query()->select(self::LIST_COLUMNS)->with('permissions')->find($id);
    }

    /**
     * 依帳號查詢使用者
     *
     * @param string $account
     * @return User|null
     */
    public function findByAccount($account)
    {
        return User::query()->where('account', $account)->first();
    }

    /**
     * 依帳號查詢使用者（含密碼，登入驗證用）
     *
     * @param string $account
     * @return User|null
     */
    public function findByAccountForLogin($account)
    {
        return User::query()
            ->select(['id', 'account', 'password', 'status'])
            ->where('account', $account)
            ->first();
    }

    /**
     * 新增使用者
     *
     * @param array $attributes
     * @return User
     */
    public function create($attributes)
    {
        return User::query()->create($attributes);
    }

    /**
     * 更新使用者
     *
     * @param User  $user
     * @param array $attributes
     * @return User
     */
    public function update(User $user, $attributes)
    {
        $user->update($attributes);

        return $user;
    }

    /**
     * 軟刪除使用者
     *
     * @param User $user
     * @return bool
     */
    public function softDelete(User $user)
    {
        return (bool) $user->delete();
    }

    /**
     * 同步帳號權限（全部取代）
     *
     * @param User  $user
     * @param array $keywords 權限 keyword 陣列
     * @return void
     */
    public function syncPermissions(User $user, $keywords)
    {
        DB::transaction(function () use ($user, $keywords) {
            UserPermission::query()->where('user_id', $user->id)->delete();

            if (empty($keywords)) {
                return;
            }

            $rows = array_map(function ($keyword) use ($user) {
                return [
                    'user_id' => $user->id,
                    'permission_keyword' => $keyword,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $keywords);

            UserPermission::query()->insert($rows);
        });
    }

    /**
     * 取得所有客服帳號（含停用，用於統計）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllCsUsers()
    {
        return User::query()
            ->select(['id', 'account', 'nickname', 'status'])
            ->where('level', config('constants.USER.LEVEL.CS'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得所有客服帳號（排除停用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCsUsers()
    {
        return User::query()
            ->select(['id', 'account', 'nickname', 'status'])
            ->where('level', config('constants.USER.LEVEL.CS'))
            ->where('status', '!=', config('constants.USER.STATUS.DEACTIVATE'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得所有正常狀態帳號（下拉選單用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveForDropdown()
    {
        return User::query()
            ->select(['id', 'nickname'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->where('level', '!=', config('constants.USER.LEVEL.ADMIN'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得主管與老闆（自動回覆求助單第二階段提醒用）
     *
     * level <= LEADER 即管理者(0)、老闆(1)、主管(2)，
     * 工程(3) 與客服(4) 都不在內 —— 求助單升級後不該去吵工程。
     * 只取有填 Telegram 帳號的人，沒填的 tag 不到。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getManagersForMention()
    {
        return User::query()
            ->select(['id', 'nickname', 'telegram_username'])
            ->where('level', '<=', config('constants.USER.LEVEL.LEADER'))
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->whereNotNull('telegram_username')
            ->where('telegram_username', '!=', '')
            ->orderBy('level')
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 整批取私訊資料（通知收件人用）
     *
     * ⚠ **`telegram_dm_ready` 與 `level` 都一定要在 select 裡。**
     * 少了前者所有人都被判定成沒綁定；少了後者 `$user->level` 會是 null，
     * `(int) null === 0` 剛好等於 ADMIN —— `StaffDmService` 的「排除管理者」
     * 就會把**每一個人**都排除掉，而且完全不報錯。
     *
     * @param array $ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForDmByIds($ids)
    {
        $ids = array_filter((array) $ids);

        if (blank($ids)) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()
            ->select(['id', 'nickname', 'telegram_user_id', 'telegram_dm_ready', 'status', 'level'])
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * 可以當私訊收件人的帳號清單（通知設定頁用）
     *
     * ⚠ **排除管理者**（需求方 2026-10-06 指定）—— 管理者是系統維護用的帳號，
     * 不是收班表與統計的對象。這點跟 `getActiveForDropdown()` 一致。
     *
     * 帶著綁定狀態：設定頁要能直接顯示「這個人還沒私訊過機器人」，
     * 不然勾了之後只會默默發不出去。
     *
     * ⚠ `$excludeLeaderUp` 是給**報表的個人版**用的（需求方 2026-10-11）：
     * 打卡報表與超時統計都不把主管以上（Admin / Boss / Leader）算進統計對象，
     * 名單濾掉了卻還發個人版，那則會是一份不存在的統計。
     * **預設不排除** —— 班表通知與任務通知的收件人仍包含主管。
     *
     * ⚠ select 要帶 `level`：呼叫端判斷身份靠它，漏掉的話 `isLeaderUp()`
     * 讀到的是 null，所有人都會被當成主管。
     *
     * @param bool $excludeLeaderUp 連主管以上一起排除
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDmCandidates($excludeLeaderUp = false)
    {
        /*
         * **level 越小官越大**，所以兩種名單是同一個條件、只差門檻 ——
         * 「排除到哪個身份為止」。分成兩個 where 分支只會多一個 if。
         *
         * ADMIN 是 0（最小值），所以預設的 `level > 0` 等於原本的 `level != 0`。
         */
        $exclusiveLevel = $excludeLeaderUp
            ? config('constants.USER.LEVEL.LEADER')
            : config('constants.USER.LEVEL.ADMIN');

        return User::query()
            ->select(['id', 'nickname', 'level', 'telegram_user_id', 'telegram_dm_ready'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->where('level', '>', $exclusiveLevel)
            ->orderBy('level')
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 依 Telegram 身份找人（擋多重綁定用）
     *
     * ⚠ `telegram_user_id` 只有 index、**沒有 unique**，所以同一個 Telegram
     * 身份理論上可以出現在多列 —— 那會讓那個人每天收到兩份班表與任務卡。
     * 綁定前用這支擋住。
     *
     * @param int|string $telegramUserId
     * @param int|null   $exceptUserId 排除誰（通常是正要綁的那個人自己）
     * @return User|null
     */
    public function findByTelegramUserId($telegramUserId, $exceptUserId = null)
    {
        $query = User::query()
            ->select(['id', 'nickname', 'account', 'telegram_user_id'])
            ->where('telegram_user_id', (int) $telegramUserId);

        if (filled($exceptUserId)) {
            $query->where('id', '!=', (int) $exceptUserId);
        }

        return $query->first();
    }

    /**
     * 驗證碼綁定成功：寫入 Telegram 身份
     *
     * ⚠ **順便回填 `telegram_username`**，所以管理者不必再手動輸入那個欄位。
     * 它仍然有用（求助單提醒要在群組 `@` 他），只是不再是綁定的依據。
     * Telegram 那邊沒設 username 的人會是 null，那不影響私訊。
     *
     * @param User        $user
     * @param int|string  $telegramUserId
     * @param string|null $username
     * @return void
     */
    public function bindTelegram(User $user, $telegramUserId, $username = null)
    {
        $attributes = [
            'telegram_user_id'  => (int) $telegramUserId,
            'telegram_dm_ready' => true,
        ];

        if (filled($username)) {
            $attributes['telegram_username'] = ltrim(trim((string) $username), '@');
        }

        $user->forceFill($attributes)->save();
    }

    /**
     * 解除 Telegram 綁定
     *
     * ⚠ **不清 `telegram_username`**：那是在群組 `@` 他用的，
     * 跟「能不能私訊」是兩件事。
     *
     * @param User $user
     * @return void
     */
    public function clearTelegramBinding(User $user)
    {
        $user->forceFill([
            'telegram_user_id'  => null,
            'telegram_dm_ready' => false,
        ])->save();
    }

    /**
     * 依 Telegram 帳號找人
     *
     * Telegram 那邊傳來的 username 不帶 @，而後台可能填成 `@name` 或 `name`，
     * 所以兩邊都去掉 @ 再比。大小寫不敏感 —— Telegram 的 username 本來就是。
     *
     * ⚠ **這支不再用於私訊綁定**（2026-10-09 改走驗證碼，見 `TelegramBindService`）。
     * 剩下的用途是「認出群組裡發言的是哪位同仁」—— 匯率決定者、不自動回覆名單。
     * 那些只是辨識，認錯的後果是記錯名字，不像綁定會把內部通知送給外人。
     *
     * 帶 `telegram_user_id` 是給 StaffIgnoreService 的回填用的：
     * 它要判斷這筆有沒有補過 ID，少了這欄會每次都重寫一遍。
     *
     * @param string|null $username
     * @return User|null
     */
    public function findByTelegramUsername($username)
    {
        $clean = ltrim(trim((string) $username), '@');

        if (blank($clean)) {
            return null;
        }

        return User::query()
            // ⚠ telegram_dm_ready 一定要帶：少了它 TelegramChatService 讀到的永遠是
            // null，於是每次私訊都被當成「還沒綁定」而重複回覆確認訊息
            ->select(['id', 'nickname', 'telegram_username', 'telegram_user_id', 'telegram_dm_ready', 'level', 'status'])
            ->whereRaw('LOWER(TRIM(LEADING "@" FROM telegram_username)) = ?', [mb_strtolower($clean)])
            ->first();
    }

    /**
     * 不自動回覆的後台帳號（有 Telegram 身分的那些）
     *
     * 「後台帳號一律不自動回覆」的名單來源。只撈**正常狀態**的帳號 ——
     * 鎖定（可登入但不能報班）與停用都不在內，離職或停權的人發言時
     * 系統照常自動回覆。
     *
     * 條件是「有 username **或**有回填過的 ID」：回填過 ID 的人即使後來
     * 把 username 清空，仍然認得出來。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTelegramIdentitiesForAutoReply()
    {
        return User::query()
            ->select(['id', 'nickname', 'telegram_username', 'telegram_user_id'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->where(function ($query) {
                $query->where(function ($sub) {
                    $sub->whereNotNull('telegram_username')
                        ->where('telegram_username', '!=', '');
                })->orWhereNotNull('telegram_user_id');
            })
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 依 id 取暱稱（純粹要個名字顯示時用）
     *
     * ⚠ 不要拿 `getMentionableByIds()` 當這件事用 —— 那支是為「內部群組
     * tag 人」寫的：排除工程、只取正常狀態、而且**必須有 telegram_username**。
     * 用它查人名會莫名其妙漏掉一整批人。
     *
     * 這支**不加任何狀態條件**：已經離職或停用的人，他留下的紀錄仍然要顯示
     * 得出是誰。
     *
     * @param array $ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNamesByIds($ids)
    {
        if (blank($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return User::query()
            ->select(['id', 'account', 'nickname', 'level'])
            ->whereIn('id', $ids)
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 回填 Telegram ID 用的精簡查詢
     *
     * 不用 `find()`：那支的 select 是 `LIST_COLUMNS`（**沒有 `telegram_user_id`**），
     * 讀出來的屬性會是 null，回填就會判斷成「還沒補過」而每則訊息重寫一次；
     * 它還會 eager load `permissions`，對這件事完全是白撈。
     *
     * @param int $id
     * @return User|null
     */
    public function findForTelegramBackfill($id)
    {
        return User::query()
            ->select(['id', 'telegram_user_id'])
            ->find($id);
    }

    /**
     * 正常狀態、但認不出 Telegram 身分的帳號
     *
     * 面板要列出來提醒去補 —— 這些人在客戶群組發言時不會被屏蔽，
     * 系統會把他們當成客人來自動回覆。
     *
     * 回填過 ID 的人即使沒填 username 也認得出來，所以不算在內。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMissingTelegramIdentity()
    {
        return User::query()
            ->select(['id', 'nickname'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->whereNull('telegram_user_id')
            ->where(function ($query) {
                $query->whereNull('telegram_username')
                    ->orWhere('telegram_username', '');
            })
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 依 id 取得可 @ 的帳號（自動回覆求助單第一階段提醒用）
     *
     * 傳入當下排班的人員 id，濾掉工程與沒填 Telegram 帳號的人。
     *
     * @param array $ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMentionableByIds($ids)
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return User::query()
            ->select(['id', 'nickname', 'telegram_username'])
            ->whereIn('id', $ids)
            ->where('level', '!=', config('constants.USER.LEVEL.ENGINEER'))
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->whereNotNull('telegram_username')
            ->where('telegram_username', '!=', '')
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得所有客服帳號（含到職日與設備）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllCsUsersWithDetail()
    {
        return User::query()
            ->select(['id', 'account', 'nickname', 'level', 'status', 'hired_at', 'resigned_at', 'equipments'])
            ->where('level', '!=', config('constants.USER.LEVEL.ADMIN'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得帳號的權限 keyword 清單
     *
     * @param User $user
     * @return array
     */
    public function getPermissionKeywords(User $user)
    {
        return $user->permissions()->pluck('permission_keyword')->all();
    }

    /**
     * 儲存 Web Push 訂閱資訊
     *
     * @param User   $user
     * @param string $endpoint
     * @param string $p256dhKey
     * @param string $authToken
     * @return User
     */
    public function savePushSubscription(User $user, $endpoint, $p256dhKey, $authToken)
    {
        $user->update([
            'push_endpoint'    => $endpoint,
            'push_p256dh_key'  => $p256dhKey,
            'push_auth_token'  => $authToken,
        ]);

        return $user;
    }

    /**
     * 清除 Web Push 訂閱資訊
     *
     * @param User $user
     * @return User
     */
    public function clearPushSubscription(User $user)
    {
        $user->update([
            'push_endpoint'    => null,
            'push_p256dh_key'  => null,
            'push_auth_token'  => null,
        ]);

        return $user;
    }

    /**
     * 取得所有已訂閱 Web Push 的使用者
     *
     * @param int|null $excludeId 排除的使用者 ID
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSubscribedUsers($excludeId = null)
    {
        $query = User::query()
            ->select(['id', 'push_endpoint', 'push_p256dh_key', 'push_auth_token'])
            ->whereNotNull('push_endpoint');

        if (filled($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get();
    }
}
