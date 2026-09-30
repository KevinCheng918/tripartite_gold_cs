<?php

namespace App\Http\Requests\Attendance;

use App\Models\ClockAmendment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 審核補打卡驗證
 */
class RespondAmendRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 只收「通過」與「駁回」—— 待審核（0）是初始狀態，
             * 沒有「改回待審核」這個動作。
             */
            'status' => [
                'required',
                'integer',
                'in:' . ClockAmendment::STATUS_APPROVED . ',' . ClockAmendment::STATUS_REJECTED,
            ],
        ];
    }
}
