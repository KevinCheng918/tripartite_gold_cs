<?php

namespace App\Http\Requests\StaffManage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'category'     => 'nullable|string|max:50',
            'name'         => 'required|string|max:200',
            'amount'       => 'required|numeric|min:0.01',
            'currency'     => 'required|string|in:TWD,USD,USDT',
            // 外幣支出必須帶匯率才換算得出台幣，台幣支出則不需要
            'exchange_rate' => 'required_unless:currency,TWD|nullable|numeric|min:0.0001',
            'expense_date' => 'required|date',
            'reimbursed'   => 'sometimes|integer|in:0,1',
            'note'         => 'nullable|string|max:500',
        ];
    }
}
