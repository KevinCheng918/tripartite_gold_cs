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
     * 全部範本，依公告編號排序（需求方 2026-10-13）
     *
     * ⚠ **沒有編號的排最後**。`code` 是 nullable（這個欄位是後來才加的，
     * 既有資料還沒補），而 MySQL 的 `ORDER BY ... ASC` 會把 NULL 排在最前面 ——
     * 一進畫面先看到一排「-」，真正有編號的反而被推到下面。
     *
     * ⚠ 字串排序，不是數值：編號是人工訂的格式（`2026-001`、`AD-10`），
     * 本來就沒有數值可言。
     *
     * @return Collection
     */
    public function getAll()
    {
        return BroadcastTemplate::query()
            ->select(self::LIST_COLUMNS)
            ->with('creator')
            ->orderByRaw('code IS NULL, code ASC')
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
     * 依代號找（Telegram 指令預約公告用）
     *
     * ⚠ **完全相符，不做模糊比對。** 「T01」與「T10」只差一個字，
     * 猜錯的後果是錯誤的公告發到客戶群組 —— 找不到就該說找不到，
     * 讓人重打一次比較便宜。
     *
     * ⚠ 大小寫不計：使用者在手機上打 `t01` 是常態。
     * `code` 是 UNIQUE，所以不會因為大小寫而撞出兩筆。
     *
     * @param string $code
     * @return BroadcastTemplate|null
     */
    public function findByCode($code)
    {
        $code = trim((string) $code);

        if (blank($code)) {
            return null;
        }

        return BroadcastTemplate::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->first();
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
