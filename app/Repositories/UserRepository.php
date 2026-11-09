<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
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
     *
     * ⚠ 出勤模式那五欄（`work_mode` 起）**一定要在這裡**：`find()` 回來的 User
     * 就是 `AttendanceService::resolveWorkTime()` 拿來判斷固定班的那一個。
     * 少了 `work_mode`，`isFixedWorkMode()` 會安靜地一律回 false ——
     * 固定班的人打卡又變回「什麼都算不出來」，而且不會有任何錯誤。
     */
    private const LIST_COLUMNS = ['id', 'account', 'nickname', 'telegram_nickname', 'telegram_username', 'telegram_user_id', 'telegram_dm_ready', 'status', 'level', 'project_ids', 'hired_at', 'equipments', 'created_at', 'work_mode', 'work_start_time', 'work_end_time', 'work_days', 'show_in_shift'];

    /**
     * 這一次請求撈過的「可指派成專案負責人的帳號」
     *
     * ⚠ 存在的理由：專案管理那一頁會問兩次 —— 「變更負責人」的下拉要逐專案篩
     * （`getOwnerCandidatesByProject()`），「新增專案」的下拉要全部
     * （`getAssignableForOwner()`）。沒有這個 memo 就是兩趟一模一樣的查詢。
     *
     * @var Collection|null
     */
    private $assignableForOwner = null;

    /**
     * 上面那一份撈過了沒
     *
     * ⚠ **要一個旗標，不能用「`$assignableForOwner` 是不是空的」代替**：
     * 空 Collection 是合法的結果（一個可指派的帳號都沒有），
     * 跟「還沒查」是兩回事 —— 混在一起的話那個情況每次都會重查。
     *
     * @var bool
     */
    private $assignableForOwnerLoaded = false;

    /**
     * 依條件分頁查詢使用者
     *
     * @param array $filters 篩選條件：account／nickname（模糊）、status／level（精確，
     *                       **0 也算有效值**）、exclude_id（排除某人，通常是操作者自己）、
     *                       min_level（只看階層大於等於這個數字的人）
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($filters = [], $perPage = 20)
    {
        $query = User::query()->select(self::LIST_COLUMNS)->with('permissions');

        $account = Arr::get($filters, 'account');

        if (filled($account)) {
            $query->where('account', 'like', "%{$account}%");
        }

        $nickname = Arr::get($filters, 'nickname');

        if (filled($nickname)) {
            $query->where('nickname', 'like', "%{$nickname}%");
        }

        /*
         * ⚠ `status` 與 `level` **一定要用 `filled()` 判斷，不能用 `empty()`
         * 或真值判斷**：兩者的合法值都包含 **0**（`status` 的停用、`level` 的
         * 管理者）。`empty(0)` 是 true，會讓「只看管理者」「只看停用帳號」
         * 這兩個篩選**安靜地變成不篩選**，列出全部人。
         *
         * `filled(0)` 在 Laravel 是 true —— `blank()` 對 `is_numeric` 一律
         * 回 false，數字永遠不算空。
         */
        $status = Arr::get($filters, 'status');

        if (filled($status)) {
            $query->where('status', (int) $status);
        }

        $level = Arr::get($filters, 'level');

        if (filled($level)) {
            $query->where('level', (int) $level);
        }

        $excludeId = Arr::get($filters, 'exclude_id');

        if (filled($excludeId)) {
            $query->where('id', '!=', (int) $excludeId);
        }

        /*
         * ⚠ `min_level` 是「操作者只能看比自己低階的人」，由
         * `AccountController` 傳 `$operator->level + 1` ——
         * 管理者（0）不會帶這個條件，所以這裡不必處理 0。
         */
        $minLevel = Arr::get($filters, 'min_level');

        if (filled($minLevel)) {
            $query->where('level', '>=', (int) $minLevel);
        }

        /*
         * ⚠ **先照階層排**（需求方 2026-10-30）：階層小的在前（管理者 0 →
         * 老闆 1 → 組長 2 → 工程 3 → 客服 4），跟取管理者名單
         * （`getManagersForMention()`）與通知收件人那幾支同一個方向 ——
         * 同一份人員在不同頁面的順序不一致，看的人會以為少了人。
         *
         * ⚠ 同階層內**維持 id 由大到小**：剛開好的帳號還是在那一群的最上面。
         * 第二順位換成 nickname 的話，新建的帳號會掉到名字排序的中間，
         * 開完帳號的人要自己找。
         */
        return $query->orderBy('level')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 可以被指派成專案負責人的帳號（全部，不分專案）
     *
     * ⚠ **新增專案的下拉要用這一支，不是 `getOwnerCandidatesByProject()`**：
     * 專案是存檔那一刻才建立的，在那之前沒有人勾得了「參與它」——
     * 用逐專案那一支的話名單會只剩管理者，這個欄位等於沒用。
     * 存檔後專案卡片會標紅字提醒去帳號管理把他加入參與專案。
     *
     * ⚠ 只收**啟用中**的帳號：停用或離職的人不該出現在下拉裡。
     * （已經被指派的那些仍然保留並標紅字，那是另一回事。）
     *
     * ⚠ **排除管理者**（需求方 2026-10-30：「指派人不能有 admin」）。
     * 不排的話管理者會出現在**每一個**專案的名單裡 ——
     * `canAccessProject()` 對管理者一律回 true，而那個 true 的本意是
     * 「他看得到所有專案」，不是「他適合當每個專案的負責人」。
     *
     * ⚠ 用 SQL 擋（`where level != ADMIN`）而不是撈回來再 `isAdmin()` 篩：
     * 這是「這一頁要哪些人」的條件，屬於查詢，不該由 PHP 事後過濾。
     *
     * ⚠ `select` 要含 `level` 與 `project_ids` —— `canAccessProject()` 要它們；
     * `level` 同時也是這裡排除管理者的依據。
     *
     * @return Collection
     */
    public function getAssignableForOwner()
    {
        // 短路：同一次請求問第二次就不要再查了（理由見 `$assignableForOwnerLoaded`）
        if ($this->assignableForOwnerLoaded) {
            return $this->assignableForOwner;
        }

        $this->assignableForOwnerLoaded = true;

        $this->assignableForOwner = User::query()
            ->select(['id', 'nickname', 'account', 'level', 'project_ids'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            // 管理者不可以當負責人（需求方 2026-10-30），理由見上面的註解
            ->where('level', '!=', config('constants.USER.LEVEL.ADMIN'))
            ->orderBy('level')
            ->orderByDesc('id')
            ->get();

        return $this->assignableForOwner;
    }

    /**
     * 可以被指派成專案負責人的帳號（逐專案篩過）
     *
     * 需求方 2026-10-30 裁示：**只有已勾選「參與這個專案」的人**。
     *
     * ⚠ **一次撈完再在 PHP 分專案，不要逐專案 `whereJsonContains`**：
     * `project_ids` 裡面可能存成字串（`["1","3"]`），`whereJsonContains`
     * 用整數比就全部對不上 —— 而且逐專案查是一個專案一趟。客服帳號的量級
     * 本來就小，一次撈完最省也最準。同 `TaskRepository::getOpenForNotice()`
     * 的理由。
     *
     * ⚠ 判準用 `User::canAccessProject()`，**不要在這裡再寫一次
     * `in_array(... project_ids ...)`** —— 那是同一條規則的第二份實作，
     * 分岔的那一邊就是錯的那一邊（規章那次的教訓）。
     *
     * ⚠ **管理者不會出現在結果裡，但不是這一支擋的** ——
     * `canAccessProject()` 對管理者一律回 true（他確實看得到每一個專案），
     * 所以**光靠這裡的篩選管理者會出現在每一個專案的名單裡**。
     * 排除管理者那一關在 `getAssignableForOwner()`
     * （需求方 2026-10-30：「指派人不能有 admin」）。
     *
     * ⚠ 「只收啟用中的帳號」那一關也在 `getAssignableForOwner()`，
     * 這裡只負責「有沒有參與這個專案」。
     *
     * @return \Illuminate\Support\Collection<int, Collection> 專案 id => 該專案可指派的帳號
     */
    public function getOwnerCandidatesByProject(array $projectIds)
    {
        if (blank($projectIds)) {
            return collect();
        }

        $users = $this->getAssignableForOwner();

        $byProject = collect();

        foreach ($projectIds as $projectId) {
            $byProject->put((int) $projectId, $users->filter(function ($user) use ($projectId) {
                return $user->canAccessProject($projectId);
            })->values());
        }

        return $byProject;
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
     * ⚠ **這一支不含密碼，不要拿來做登入驗證** —— 登入走
     * `findByAccountForLogin()`。看到這裡沒有密碼就「順手」改成 `select *`
     * 的話，等於把密碼撈進每一次查詢。
     *
     * @param string $account
     * @return User|null
     */
    public function findByAccount($account)
    {
        return User::query()->select(self::LIST_COLUMNS)->where('account', $account)->first();
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

            // 清空權限是合法操作 —— 刪完就結束，不要再往下組 insert
            if (blank($keywords)) {
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
     * 固定上下班時間、仍在職的帳號（曠工掃描與排班紀錄合成用）
     *
     * ⚠ **一定要排掉離職的人**：`status = 停用` 只是不能登入，真正的離職
     * 看 `resigned_at`。兩個都不濾的話，離職同仁會從離職那天起每個上班日
     * 被標一筆曠工，一路標到有人發現為止。
     *
     * ⚠ 設定不完整（勾了固定班卻沒填起訖）的也排掉 —— 算不出標準時間，
     * 判他曠工沒有依據。
     *
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    public function getFixedWorkModeUsers()
    {
        return User::query()
            ->select(['id', 'nickname', 'level', 'status', 'work_mode', 'work_start_time', 'work_end_time', 'work_days', 'show_in_shift'])
            ->where('work_mode', config('constants.USER.WORK_MODE.FIXED'))
            ->where('status', '!=', config('constants.USER.STATUS.DEACTIVATE'))
            ->whereNull('resigned_at')
            ->whereNotNull('work_start_time')
            ->whereNotNull('work_end_time')
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 這段期間「照設定本來就該上班」的固定班同仁（只回 id）
     *
     * 固定班的人（工程、或設了固定時間的客服）**沒有排班紀錄** ——
     * 排班表上的色塊是依帳號設定合成出來的。所以打卡報表要補人時，
     * 光看 `shift_assignment` 會漏掉他們。
     *
     * ⚠ **這一支是「區間版」，不要拿 `getFixedWorkModeUsers()` 代替。**
     * 那一支是給曠工掃描用的「今天誰該上班」，條件是 `resigned_at IS NULL`
     * ——查歷史區間時會有兩個方向的錯：**當時在職、現在已離職**的人會被
     * 整個漏掉，而**當期之後才到職**的人會被憑空加進去（一整列 0）。
     *
     * 所以這裡改成跟區間比對：到職日在區間結束前、離職日在區間開始後。
     * 兩個日期都允許 null（沒填到職日的舊帳號不該因此消失）。
     *
     * ⚠ 其餘條件照 `getFixedWorkModeUsers()`：停用的排掉、
     * 固定班卻沒填起訖時間的也排掉（算不出標準時間）。
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d（含當天）
     * @return array<int>
     */
    public function getFixedWorkModeUserIdsInRange($dateFrom, $dateTo)
    {
        $ids = User::query()
            ->where('work_mode', config('constants.USER.WORK_MODE.FIXED'))
            ->where('status', '!=', config('constants.USER.STATUS.DEACTIVATE'))
            ->whereNotNull('work_start_time')
            ->whereNotNull('work_end_time')
            ->where(function ($query) use ($dateTo) {
                $query->whereNull('hired_at')->orWhere('hired_at', '<=', $dateTo);
            })
            ->where(function ($query) use ($dateFrom) {
                $query->whereNull('resigned_at')->orWhere('resigned_at', '>=', $dateFrom);
            })
            ->pluck('id')
            ->all();

        return array_map('intval', $ids);
    }

    /**
     * 要檢查打卡提醒的在職同仁
     *
     * ⚠ **不分輪班／固定班一次全撈**：打卡提醒每分鐘跑一次，逐人查是
     * 一天幾萬次查詢。撈回來之後由 `AttendanceService` 一次算出每個人
     * 當天的上下班時間（輪班的排班也是一次撈完）。
     *
     * ⚠ 條件照 `getFixedWorkModeUsers()` 的標準：**停用與離職都要排掉**。
     * `status = 停用` 只是不能登入，真正的離職看 `resigned_at` ——
     * 兩個都不濾的話，離職同仁會被一路提醒到有人發現為止。
     *
     * ⚠ select 要帶固定班那四個欄位 —— 固定班的上下班時間存在帳號上，
     * 漏掉的話 `resolveWorkTime()` 一律回 null，固定班的人永遠收不到提醒。
     *
     * ⚠ 也要帶 `telegram_user_id` 與 `telegram_dm_ready`：送出端
     * （`StaffDmService::canDm()`）要靠它判斷發不發得出去，
     * 不帶的話每個人都會被當成沒綁定。
     *
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    public function getForClockReminder()
    {
        return User::query()
            ->select([
                'id', 'nickname', 'level', 'status',
                'work_mode', 'work_start_time', 'work_end_time', 'work_days',
                'telegram_user_id', 'telegram_dm_ready',
            ])
            ->where('status', '!=', config('constants.USER.STATUS.DEACTIVATE'))
            ->whereNull('resigned_at')
            ->orderBy('id')
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
            /*
             * ⚠ **`project_ids` 一定要撈**（2026-11-03 加）。
             *
             * 任務看板的參與人清單要照卡片所屬專案過濾，而判斷依據是
             * `User::projectIdList()` 讀的這一欄 —— 沒撈的話它永遠是空陣列，
             * **於是每一個人看起來都沒參與任何專案，清單會變成全空**。
             *
             * ⚠ 這是這個 Repository 同一種坑的第三次（前兩次是
             * `findByTelegramUserId()` 漏 `level` 與漏 `project_ids`）：
             * **欄位沒撈不會報錯**，只會讓依賴它的判斷安靜地全部走錯邊。
             * 在這支加任何「依使用者判斷可見範圍」的功能前，先確認欄位在不在。
             */
            ->select(['id', 'nickname', 'project_ids'])
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

        /*
         * ⚠ 空清單直接回空集合，不要 `whereRaw('1 = 0')->get()` ——
         * 那是一趟必然沒結果的查詢，而且是 `select *`。
         * 跟 `getMentionableByIds()` 同一個寫法。
         */
        if (blank($ids)) {
            return new Collection();
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
     * 可以被撥電話的帳號清單（逾時升級的測試撥號用）
     *
     * ⚠ **跟 `getDmCandidates()` 條件相同但欄位不同**，不要互相取代：
     * 撥號要 `telegram_username`（userbot 靠它找到人），私訊要
     * `telegram_user_id` ＋ `telegram_dm_ready`。那一支沒有 username，
     * 拿來撥號會每個人都撥不出去。
     *
     * ⚠ 沒填 `telegram_username` 的人**直接不列**：撥不出去的人出現在
     * 測試的下拉選單裡只會讓人以為是功能壞了。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCallCandidates()
    {
        /*
         * ⚠⚠ **條件要跟 `getMentionableByIds()` 一模一樣**（除了少一個 whereIn）。
         *
         * 那一支才是逾時升級真正撥號時用的名單。兩邊條件一旦走偏，測試就失去
         * 意義：排除 ADMIN 的話，真的會被撥的管理者永遠測不到；沒排除 ENGINEER
         * 的話，測得通的工程師在正式流程裡根本不會被撥。
         *
         * 改這裡請連同 `getMentionableByIds()` 一起改。
         */
        return User::query()
            ->select(['id', 'nickname', 'telegram_username'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->where('level', '!=', config('constants.USER.LEVEL.ENGINEER'))
            ->whereNotNull('telegram_username')
            ->where('telegram_username', '!=', '')
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
        /*
         * ⚠ **一定要 select `level`**（2026-10-30 修）。
         *
         * 所有小幫手指令都拿這支撈下指令的人，然後 `hasPermission()` 驗權限。
         * 而 `hasPermission()` 第一行是 `isAdmin()` —— 沒撈 `level` 的話
         * `$user->level` 是 null，舊版的 `null == 0`（管理者是 0）成立，
         * **每一支指令對任何綁定過 Telegram 的帳號都直接放行**，
         * 包括會發訊息給客戶的公告與餘點告警。
         *
         * `isAdmin()` 那一邊也補了防線，但這一欄本來就該撈。
         *
         * ⚠ **`project_ids` 也一定要撈**（2026-11-01 修，同一種坑又踩一次）。
         *
         * 小幫手開卡要問「這個人看得到哪幾個專案」，而
         * `User::projectIdList()` 讀的就是這一欄 —— 沒撈的話它是 null，
         * `getVisibleTo()` 回空集合，於是**參與專案明明每個都打勾的人，
         * 也會被告知「你沒有參與任何專案」**。
         *
         * 這一類錯**不會報錯也不會留 log**，只會變成一句看起來像資料設定
         * 問題的訊息 —— 回報的人會先去翻帳號設定，而那邊是對的。
         */
        $query = User::query()
            ->select(['id', 'nickname', 'account', 'level', 'project_ids', 'telegram_user_id'])
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
            return new Collection();
        }

        return User::query()
            /*
             * ⚠ `telegram_username` 是 2026-11-01 加的：任務卡異動通知要把
             * 指派人員與專案負責人變成 `@mention`，而**沒有 username 的人要
             * 退回顯示暱稱**，所以必須撈這一欄自己判斷。
             *
             * ⚠ 不要改用 `getMentionableByIds()` —— 那支會濾掉工程、
             * 非正常狀態、以及沒有 username 的人，用在這裡會讓那些人
             * 從通知上整個消失（這一支的 docblock 上方已經寫過這個警告）。
             */
            ->select(['id', 'account', 'nickname', 'level', 'telegram_username'])
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
        // 空清單就不要打 DB —— `whereIn('id', [])` 是一趟必然沒結果的查詢
        if (blank($ids)) {
            return new Collection();
        }

        // 條件與 `getCallCandidates()`（測試撥號的名單）必須一致，理由寫在那一支
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
            /*
             * ⚠ **`work_mode` 一定要撈**（2026-11-05 加）：人員列表要顯示
             * 本年度特休，而「一日幾小時」是看出勤模式的（輪班 12／固定班 8）。
             * 沒撈的話 `LeaveQuotaService::dayMinutes()` 會把每個人都當成
             * 輪班，固定班的人額度**憑空多出一半**，而且不會報錯。
             *
             * ⚠ 這是這個 Repository 同一種坑的第四次（`level`、`project_ids`
             * ×2）——**在這支加任何「依使用者屬性判斷」的功能前，
             * 先確認那個欄位在不在 select 裡。**
             */
            ->select(['id', 'account', 'nickname', 'level', 'status', 'hired_at', 'resigned_at', 'equipments', 'work_mode'])
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
