<?php

namespace App\Repositories;

use App\Models\BroadcastTemplate;
use Illuminate\Database\Eloquent\Collection;

/**
 * 公告範本 Repository
 */
class BroadcastTemplateRepository
{
    /**
     * 列表要的欄位
     *
     * ⚠ 含 `content` 與兩組附件：列表要顯示摘要與附件數，而且選了範本之後
     * 前端直接從手上這份資料帶進表單 —— 不另外打一次「取單筆」。
     * 範本不多（人工建檔），整包回去比多一次往返划算。
     *
     * @var array
     */
    private const LIST_COLUMNS = [
        'id', 'code', 'title', 'content', 'image_urls', 'file_urls', 'created_by', 'updated_at',
    ];

    /**
     * 全部範本（最近改過的在前面）
     *
     * @return Collection
     */
    public function getAll()
    {
        return BroadcastTemplate::query()
            ->select(self::LIST_COLUMNS)
            ->with('creator')
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @param int $id
     * @return BroadcastTemplate|null
     */
    public function find($id)
    {
        return BroadcastTemplate::query()->find($id);
    }

    /**
     * @param array $attributes
     * @return BroadcastTemplate
     */
    public function create($attributes)
    {
        return BroadcastTemplate::query()->create($attributes);
    }

    /**
     * @param BroadcastTemplate $template
     * @param array             $attributes
     * @return bool
     */
    public function update(BroadcastTemplate $template, $attributes)
    {
        return $template->update($attributes);
    }

    /**
     * 刪除範本
     *
     * ⚠ **只刪這一列，不碰實體檔**。發送紀錄存的是複製過去的同一組路徑，
     * 刪檔會讓歷史紀錄裡的附件變成死連結（需求方 2026-10-13 的選擇）。
     *
     * @param BroadcastTemplate $template
     * @return bool
     */
    public function delete(BroadcastTemplate $template)
    {
        return (bool) $template->delete();
    }
}
