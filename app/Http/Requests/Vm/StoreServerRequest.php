<?php

namespace App\Http\Requests\Vm;

/**
 * 新增虛擬機驗證
 *
 * 欄位限制與 UpdateServerRequest 共用，差別只在必填與否，
 * 避免像先前 billing_day 新增可填到 31、編輯卻只准到 28，
 * 導致帳單日 29 以後的主機一按編輯就驗證失敗。
 */
class StoreServerRequest extends UpdateServerRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
            'station_id'  => 'required|integer|exists:station,id',
            'hostname'    => 'required|string|max:100',
            'spec'        => 'required|string|max:255',
            'monthly_fee' => 'required|numeric|min:0',
            'billing_day' => 'required|integer|min:1|max:' . self::BILLING_DAY_MAX,
        ]);
    }
}
