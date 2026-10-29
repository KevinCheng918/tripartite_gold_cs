<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 專案 Resource
 *
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'status'      => $this->status,
            /*
             * 負責人（需求方 2026-10-30）。
             *
             * ⚠ `owner_id` 要跟 `owner` 一起回：前端的下拉要靠 id 選回正確的人，
             * 只給名字的話同名同姓就選錯了。
             *
             * ⚠ `owner_issue` 是「要不要標紅字提醒換人」——
             * resigned／disabled／not_participant／null，見
             * `Project::getOwnerIssueAttribute()`。
             * **只回代碼不回文案**：文案是三語系的，交給前端查 i18n。
             */
            'owner_id'    => $this->owner_id,
            'owner'       => filled($this->owner) ? $this->owner->nickname : null,
            'owner_issue' => $this->owner_issue,
            'creator'     => filled($this->creator) ? $this->creator->nickname : '-',
            'created_at'  => $this->created_at ? $this->created_at->format('Y-m-d H:i') : null,
            'updated_at'  => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
        ];
    }
}
