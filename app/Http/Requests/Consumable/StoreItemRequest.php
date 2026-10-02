<?php

namespace App\Http\Requests\Consumable;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 消耗品品項驗證
 *
 * 新增與修改共用。品項是客服自己維護的清單（卡片、SIM 卡…），
 * 加新的不用改程式。
 */
class StoreItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $statuses = array_values(config('constants.CONSUMABLE.STATUS'));

        return [
            'name'       => 'required|string|max:50',
            // 單位純顯示用（「剩餘 7 張」），不參與計算，所以可以不填
            'unit'       => 'nullable|string|max:10',
            'status'     => ['nullable', 'integer', Rule::in($statuses)],
            'sort_order' => 'nullable|integer|min:0',
            'note'       => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => trans('staff_manage.msg.consumable_name_required'),
            'name.max'      => trans('staff_manage.msg.consumable_name_max'),
            'unit.max'      => trans('staff_manage.msg.consumable_unit_max'),
            'note.max'      => trans('staff_manage.msg.consumable_note_max'),
        ];
    }
}
