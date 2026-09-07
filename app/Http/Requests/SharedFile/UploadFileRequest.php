<?php

namespace App\Http\Requests\SharedFile;

use App\Http\Requests\Concerns\BlocksExecutableUploads;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 文件區上傳驗證
 */
class UploadFileRequest extends FormRequest
{
    use BlocksExecutableUploads;

    /** @var int 檔案大小上限（KB） */
    public static $maxKb = 20480;

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
            'file'      => ['required', 'file', 'max:' . self::$maxKb, $this->blockedExtensionRule('shared_file.msg.file_type_blocked')],
        ];
    }

    public function messages()
    {
        return [
            'folder_id.required' => trans('shared_file.msg.folder_not_found'),
            'folder_id.exists'   => trans('shared_file.msg.folder_not_found'),
            'file.required'      => trans('shared_file.msg.file_required'),
            'file.max'           => trans('shared_file.msg.file_too_large', ['value' => self::$maxKb / 1024]),
        ];
    }
}
