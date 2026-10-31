<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 任務 Model
 *
 * @property int         $id
 * @property int         $project_id
 * @property string      $title
 * @property string|null $description
 * @property int         $status      1=待處理, 2=進行中, 3=審核中, 4=已解決
 * @property int         $priority    1=低, 2=中, 3=高, 4=緊急
 * @property int|null    $assignee_id
 * @property array|null  $assignee_ids     指派人員（負責把這件事做完的人）
 * @property array|null  $participant_ids  參與人（一起處理或需要知道，但不負責結案）
 * @property int         $creator_id
 * @property string|null $due_date
 * @property int         $sort_order
 */
class Task extends Model
{
    protected $table = 'task';

    protected $guarded = ['id'];

    protected $casts = [
        'status'     => 'integer',
        'priority'   => 'integer',
        'due_date'   => 'date',
        'sort_order'   => 'integer',
        'images'       => 'array',
        'assignee_ids' => 'array',
        /*
         * 參與人（需求方 2026-10-30）。
         *
         * ⚠ 跟 `assignee_ids` 是兩回事：指派人員負責把事做完，
         * 參與人只是一起處理或需要知道 —— 不要互換使用。
         */
        'participant_ids' => 'array',
    ];

    /**
     * @return BelongsTo
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->select(['id', 'system_id', 'name']);
    }

    /**
     * ⚠ `reactivated_at` 一定要 select：封存清單的「剩餘天數」要靠它算
     * （專案重新啟用後 30 天內不清理，見 TaskResource::purgeAt()）。
     * 少了它那一欄永遠是 null，畫面會寫「剩 0 天」但其實不會被刪。
     *
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        /*
         * ⚠ `owner_id` 是給任務卡異動通知用的（要顯示專案負責人）——
         * **少了它 `$task->project->owner` 會永遠是 null，而且不會報錯**，
         * 訊息上就安靜地變成「未指派」。這個專案為「select 漏欄位」修過好幾次。
         *
         * ⚠ 只多撈一欄，`owner` 關聯本身**不在這裡 eager load** ——
         * 看板每張卡都走這個關聯，而看板不顯示負責人，載了是白撈。
         * 要用的那一支自己 `loadMissing('project.owner')`。
         */
        return $this->belongsTo(Project::class)->select(['id', 'name', 'reactivated_at', 'owner_id']);
    }

    /**
     * 舊的單人指派（`assignee_id`）
     *
     * ⚠ **目前沒有任何地方讀這個關聯，而且不要 eager load 它。**
     * 指派人員早就改成多人（`assignee_ids`），畫面與 `TaskResource` 一律走
     * 那一邊 —— 2026-10-31 把五處 `with('assignee')` 拔掉了，
     * 它們每次都白跑一趟查詢（`assignee_id` 是 null 時還是
     * `select … from user where 0 = 1`）。
     *
     * ⚠ 關聯本身**刻意留著**：`assignee_id` 這個欄位還活著
     * （`TaskRepository::filterRelatedToMe()` 的「指派給我的」要比對它，
     * 因為早期的卡片只有單人欄位有值）。真的要取那個人的名字時用它，
     * 但先想清楚你要的是不是 `assignee_ids`。
     *
     * @return BelongsTo
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->select(['id', 'nickname']);
    }

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->select(['id', 'nickname']);
    }

    /**
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderByDesc('created_at');
    }

    /**
     * 最近一筆封存活動紀錄
     *
     * @return HasOne
     */
    public function latestArchivedActivity(): HasOne
    {
        return $this->hasOne(TaskActivity::class)
            ->select(['id', 'task_id', 'changes'])
            ->where('action', 'archived')
            ->orderByDesc('id');
    }
}
