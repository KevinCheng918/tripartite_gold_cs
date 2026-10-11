<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 報表 →「標記為已處理」
 *
 * ⚠ 只驗「這個 id 長得像不像一張單」，**不驗它現在是什麼狀態** ——
 * 狀態條件帶在 `markHandledById()` 的 update where 裡，由資料庫判誰先到。
 * 在這裡查一次狀態再放行，等於先查後寫，跟 Telegram 那邊同時按會互相覆蓋。
 */
class MarkHandledRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'id' => 'required|integer|min:1',
        ];
    }

    /**
     * id 來自路由參數，驗證要看得到它
     *
     * @return array
     */
    public function validationData()
    {
        return array_merge($this->all(), ['id' => $this->route('id')]);
    }

    public function messages()
    {
        return [
            'id.required' => trans('report.msg.ticket_missing'),
            'id.integer'  => trans('report.msg.ticket_missing'),
            'id.min'      => trans('report.msg.ticket_missing'),
        ];
    }
}
