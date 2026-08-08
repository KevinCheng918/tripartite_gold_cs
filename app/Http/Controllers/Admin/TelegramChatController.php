<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TelegramChat\ReplyRequest;
use App\Http\Resources\TelegramMessageResource;
use App\Services\TelegramChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            $file = $params['image'];
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());

            Storage::disk('public')->putFileAs('uploads/telegram', $file, $filename);
            $imageUrl = Storage::disk('public')->url("uploads/telegram/{$filename}");

            $message = $this->chatService->sendReply(
                (int) $params['group_id'],
                $params['caption'] ?? '',
                Auth::id(),
                Auth::user()->nickname,
                $imageUrl
            );

            return new TelegramMessageResource($message);
        } catch (\Exception $e) {
            Log::error('Telegram 圖片發送失敗', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ajax 對訊息送出表情回應
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxReact(Request $request)
    {
        $params = $request->validate([
            'message_id' => 'required|integer',
            'emoji'      => 'required|string|max:10',
        ]);

        try {
            $reactions = $this->chatService->sendReaction(
                (int) $params['message_id'],
                $params['emoji']
            );

            if ($reactions === null) {
                return response()->json(['message' => trans('telegram_chat.msg.reaction_failed')], 500);
            }

            return response()->json(['reactions' => $reactions]);
        } catch (\Exception $e) {
            Log::error('Telegram 表情回應失敗', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json(['message' => trans('telegram_chat.msg.reaction_failed')], 500);
        }
    }

    // 值班客服自動從排班系統指派，不需要手動操作
}
