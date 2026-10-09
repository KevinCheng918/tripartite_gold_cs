<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 任務 Resource
 *
 * @mixin \App\Models\Task
 */
class TaskResource extends JsonResource
{
    /** @var array 使用者快取（避免 N+1） */
    private static $userCache = [];

    /**
     * 預載使用者（在 collection 之前呼叫）
     *
     * @param array $userIds
     * @return void
     */
    public static function preloadUsers(array $userIds)
    {
        $missing = array_diff($userIds, array_keys(self::$userCache));
        if (empty($missing)) {
            return;
        }
        $users = User::query()
            ->select(['id', 'nickname'])
            ->whereIn('id', $missing)
            ->get();
        foreach ($users as $u) {
            self::$userCache[$u->id] = $u->nickname;
        }
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $assigneeIds = (array) $this->assignee_ids;

        /*
         * ⚠ **自己確保快取有資料，不要假設呼叫端 preload 過了。**
         *
         * 下面是「快取沒命中就跳過」，所以快取空的時候 `assignees` 會是空
         * 陣列 —— 前端照著畫就是「未指派」，**而且不會報任何錯**。
         *
         * 2026-10-06 踩到的就是這個：列表三支都有 `preloadUsers()`，只有
         * `ajaxUpdateTask()` 沒有 —— 於是在卡片裡改完任何欄位，看板上那張卡
         * 的指派人就變成「未指派」，重新整理（走列表）才會恢復。
         *
         * 放在這裡而不是去補那一支：**日後任何新的單筆回傳點都不必記得**。
         * `preloadUsers()` 會 `array_diff` 掉已經在快取裡的，所以列表那條
         * 路徑不會多查一次。
         */
        self::preloadUsers($assigneeIds);

        $assignees = [];
        foreach ($assigneeIds as $id) {
            if (isset(self::$userCache[$id])) {
                $assignees[] = ['id' => $id, 'nickname' => self::$userCache[$id]];
            }
        }

        return [
            'id'           => $this->id,
            'project_id'   => $this->project_id,
            'project'      => $this->project ? $this->project->name : '-',
            'station_id'   => $this->station_id,
            'station'      => $this->station ? $this->station->name : null,
            'system'       => $this->station && $this->station->system ? $this->station->system->name : null,
            'title'        => $this->title,
            'description'  => $this->description,
            'images'       => array_map(function ($path) {
                return asset("storage/{$path}");
            }, $this->images ?? []),
            'status'       => $this->status,
            'priority'     => $this->priority,
            'assignee_ids' => $assigneeIds,
            'assignees'    => $assignees,
            'creator'      => $this->creator ? $this->creator->nickname : '-',
            'due_date'     => $this->due_date ? $this->due_date->format('Y-m-d') : null,
            'sort_order'   => $this->sort_order,
            'created_at'   => $this->created_at->format('Y-m-d H:i'),
            'updated_at'   => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
            'purge_at'     => $this->purgeAt(),
            'previous_status' => $this->whenLoaded('latestArchivedActivity', function () {
                $changes = $this->latestArchivedActivity->changes ?? [];
                return isset($changes['狀態']['from']) ? (int) $changes['狀態']['from'] : null;
            }),
        ];
    }

    /**
     * 這張封存卡什麼時候會被真的刪掉
     *
     * ⚠ **跟 `TaskRepository::deleteArchivedOlderThan()` 是同一條規則，改一邊
     * 就要改另一邊。** 封存清單的「剩餘天數」直接讀這個值 —— 兩邊算法不同步的話，
     * 畫面上寫著還剩幾天、實際上早就被刪了（或反過來，寫 0 天卻一直都在）。
     *
     * 規則：封存後 N 天刪；但專案重新啟用後整個專案再給 N 天，取晚的那個。
     *
     * @return string|null 封存中才有值，其餘狀態回 null
     */
    private function purgeAt()
    {
        if ((int) $this->status !== (int) config('constants.TASK.STATUS.ARCHIVED') || blank($this->updated_at)) {
            return null;
        }

        $days = (int) config('constants.TASK.ARCHIVE_PURGE_DAYS');
        $from = $this->updated_at;

        $reactivatedAt = filled($this->project) ? $this->project->reactivated_at : null;

        // 重新啟用得比封存還晚才有意義 —— 早於封存時間的那次啟用跟這張卡無關
        if (filled($reactivatedAt) && $reactivatedAt->greaterThan($from)) {
            $from = $reactivatedAt;
        }

        return $from->copy()->addDays($days)->format('Y-m-d H:i');
    }
}
