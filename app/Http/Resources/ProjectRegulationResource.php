<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 系統規章 Resource
 *
 * @mixin \App\Models\ProjectRegulation
 */
class ProjectRegulationResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'project_id' => $this->project_id,
            'project'    => $this->project ? $this->project->name : '-',
            'title'      => $this->title,
            /*
             * ⚠ `content` 只有「點開看單一份」時才會帶 —— 列表用的
             * `LIST_COLUMNS` 根本沒撈它（規章可能上萬字）。
             * 用 `whenLoaded` 不行（那是給關聯用的），所以直接判斷有沒有這個屬性。
             */
            'content'    => $this->when(array_key_exists('content', $this->getAttributes()), function () {
                return (string) $this->content;
            }),
            'status'     => $this->status,
            'sort'       => $this->sort,
            'updater'    => $this->updater ? $this->updater->nickname : '-',
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
        ];
    }
}
