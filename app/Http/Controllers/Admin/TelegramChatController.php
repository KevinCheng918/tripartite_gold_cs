<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TelegramChat\ReplyRequest;
use App\Http\Resources\TelegramMessageResource;
use App\Services\TelegramChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 客服聊天控制器
 *
 * 所有登入者可查看對話，回覆和指派需要權限。
 */
class TelegramChatController extends Controller
{
    private $chatService;

    public function __construct(TelegramChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * 聊天頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.telegram-chat.index');
    }

    /**
     * Ajax 取得對話列表（群組）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxGroups()
    {
        $groups = $this->chatService->getConversationList();

        return response()->json($groups);
    }

    /**
     * Ajax 取得群組訊息
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMessages(Request $request)
    {
        $groupId = (int) $request->input('group_id');
        $perPage = (int) $request->input('per_page', 50);

        $messages = $this->chatService->getMessages($groupId, $perPage);

        return TelegramMessageResource::collection($messages);
    }

    /**
     * Ajax 回覆訊息
     *
     * @param ReplyRequest $request
     * @return \Illuminate\Http\JsonResponse|TelegramMessageResource
     */
    public function ajaxReply(ReplyRequest $request)
    {
        $params = $request->validated();

        try {
            $message = $this->chatService->sendReply(
                (int) $params['group_id'],
                $params['content'],
                Auth::id(),
                Auth::user()->nickname
            );

            return new TelegramMessageResource($message);
        } catch (\Exception $e) {
            Log::error('Telegram 回覆失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('telegram_chat.msg.reply_failed')], 500);
        }
    }

    /**
     * Ajax 發送圖片訊息
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|TelegramMessageResource
     */
    public function ajaxSendImage(Request $request)
    {
        $params = $request->validate([
            'group_id' => 'required|integer',
            'image'    => 'required|image|max:5120',
            'caption'  => 'nullable|string|max:1024',
        ]);

        try {
            // 儲存圖片到 public/uploads/telegram
            $file = $request->file('image');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/telegram'), $filename);
            $imageUrl = url("uploads/telegram/{$filename}");

            $message = $this->chatService->sendReply(
                (int) $params['group_id'],
                $params['caption'] ?? '',
                Auth::id(),
                Auth::user()->nickname,
                $imageUrl
            );

            return new TelegramMessageResource($message);
        } catch (\Exception $e) {
            Log::error('Telegram 圖片發送失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('telegram_chat.msg.reply_failed')], 500);
        }
    }

    // 值班客服自動從排班系統指派，不需要手動操作
}
