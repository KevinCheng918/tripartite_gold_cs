<?php

namespace App\Http\Requests\SharedFile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 文件區搬移檔案驗證
 */
class MoveFileRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'folder_id' => 'required|integer|exists:shared_folder,id',
        ];
    }

    public function messages()
    {
        return [
            'folder_id.required' => trans('shared_file.msg.folder_not_found'),
            'folder_id.exists'   => trans('shared_file.msg.folder_not_found'),
        ];
    }
}
