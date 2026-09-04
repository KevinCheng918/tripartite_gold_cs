<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 任務描述編輯器的圖片上傳驗證
 *
 * 與 UploadEditorFileRequest 分開：這裡只收圖片（貼上、拖曳截圖走這條），
 * 上限比一般附件小，避免編輯器內嵌超大圖拖垮載入。
 */
class UploadEditorImageRequest extends FormRequest
{
    /** @var int 圖片大小上限（KB） */
    public static $imageMaxKb = 5120;

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
            'file' => ['required', 'image', 'max:' . self::$imageMaxKb],
        ];
    }

    public function messages()
    {
        return [
            'file.required' => trans('task_board.msg.file_required'),
            'file.image'    => trans('task_board.msg.file_type_blocked'),
            'file.max'      => trans('task_board.msg.file_too_large', ['value' => self::$imageMaxKb / 1024]),
        ];
    }
}
