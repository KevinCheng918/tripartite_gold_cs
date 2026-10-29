<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 專案 Model
 *
 * @property int                             $id
 * @property string                          $name
 * @property string|null                     $description
 * @property int                             $status
 * @property \Illuminate\Support\Carbon|null $reactivated_at 上次由停用改回啟用的時間
 * @property int|null                        $owner_id       負責人（只會有一位）；null ＝ 未指派
 * @property int                             $created_by
 * @property-read string|null                $owner_issue    負責人目前的狀況代碼（Accessor）：
 *                                                           resigned／disabled／not_participant／null
 */
class Project extends Model
{
    protected $table = 'project';

    protected $guarded = ['id'];

    protected $casts = [
        'status'         => 'integer',
        'reactivated_at' => 'datetime',
    ];

    /**
     * @return HasMany
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'nickname']);
    }

    /**
     * 專案負責人（只會有一位）
     *
     * ⚠ **一定要 `withTrashed()`**：帳號是軟刪除的，而 `nullOnDelete` 只在真的
     * `DELETE` 時觸發 —— 離職的負責人 `owner_id` 還指著他。不加的話關聯被
     * `SoftDeletes` 的 global scope 濾成 null，畫面會顯示「未指派」，
     * 跟「真的沒指派」混在一起，沒有人會發現這個專案的負責人已經離職。
     *
     * ⚠ `select` 這五欄**每一欄都有人在用**，少了不會報錯但判斷會錯：
     * `status` 與 `deleted_at` 分出「已停用／已離職」，
     * `level` 與 `project_ids` 是 `canAccessProject()` 要的
     * （少 `level` 的話管理者會被判成「沒參與這個專案」）。
     *
     * @return BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')
            ->withTrashed()
            ->select(['id', 'nickname', 'status', 'level', 'project_ids', 'deleted_at']);
    }

    /**
     * 負責人目前有什麼問題（沒問題回 null）
     *
     * ⚠ 寫成 **Accessor**（`$project->owner_issue`）而不是一般方法：
     * 它純粹是把這筆資料自己的狀態換個講法，沒有決策也沒有寫入 ——
     * Model 只放 Relations／Accessors／Scope，這是 Accessor 那一類。
     *
     * ⚠ 它**只回代碼不回文案**：文案是三語系的（`project.owner_issue_*`），
     * 查 i18n 是畫面與 Resource 的事。
     *
     * ⚠ 順序就是嚴重程度，**不要調**：軟刪除的帳號 `status` 還留著原本的值、
     * `project_ids` 也還在，好幾個條件可能同時成立 —— 要講最嚴重的那一個。
     * 離職 ＞ 停用 ＞ 是管理者 ＞ 沒參與。
     *
     * ⚠ `admin` 這一種是**舊資料**：2026-10-30 加上「管理者不可以當負責人」
     * 之前指派的管理者還留在 `owner_id` 上。不標出來的話編輯視窗會顯示
     * 「未指派」（他已經不在候選名單裡了），按下確認就把負責人清空，
     * 而操作者以為自己什麼都沒改。
     *
     * ⚠ `admin` 一定要排在 `not_participant` 前面：`canAccessProject()`
     * 對管理者一律回 true，所以管理者永遠不會被判成 `not_participant` ——
     * 排在後面的話這一種永遠不會被回報。
     *
     * ⚠ `not_participant` 這一種只會從「**新增專案時一併指定負責人**」來：
     * 那一刻專案還不存在，沒有人勾得了「參與這個專案」，所以新增那條路徑
     * 不擋（擋了的話下拉只剩管理者，等於這個欄位沒用）。
     * 之後走「變更負責人」就會被 `ProjectOwnerService` 擋下來。
     * 詳見 features/project-owner.md 的「兩個裁示撞在一起」。
     *
     * @return string|null resigned ／ disabled ／ admin ／ not_participant ／ null
     */
    public function getOwnerIssueAttribute()
    {
        if (blank($this->owner)) {
            return null;
        }

        if (filled($this->owner->deleted_at)) {
            return 'resigned';
        }

        if ((int) $this->owner->status !== (int) config('constants.USER.STATUS.NORMAL')) {
            return 'disabled';
        }

        // 管理者不可以當負責人（需求方 2026-10-30）—— 這是那之前留下的舊資料
        if ($this->owner->isAdmin()) {
            return 'admin';
        }

        if (!$this->owner->canAccessProject($this->id)) {
            return 'not_participant';
        }

        return null;
    }
}
