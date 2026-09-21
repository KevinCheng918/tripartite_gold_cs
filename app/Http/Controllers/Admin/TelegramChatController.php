<?php

namespace App\Http\Controllers\Admin;

use App\Events\TelegramTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\TelegramChat\ReplyRequest;
use App\Http\Requests\TelegramChat\SendFileRequest;
use App\Http\Requests\TelegramChat\ToggleAutoReplyRequest;
use App\Http\Requests\TelegramChat\ToggleIgnoreMemberRequest;
use App\Http\Resources\GroupMemberResource;
use App\Http\Resources\TelegramMessageResource;
use App\Services\AutoReplyService;
use App\Services\ImageUploadService;
use App\Services\QuickReplyService;
use App\Services\SharedFileService;
use App\Services\TelegramChatService;
use App\Services\TelegramGroupMemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
    private $sharedFileService;
    private $quickReplyService;
    private $imageUploadService;
    private $autoReplyService;
    private $memberService;

    public function __construct(
        TelegramChatService $chatService,
        SharedFileService $sharedFileService,
        QuickReplyService $quickReplyService,
        ImageUploadService $imageUploadService,
        AutoReplyService $autoReplyService,
        TelegramGroupMemberService $memberService
    ) {
        $this->chatService = $chatService;
        $this->sharedFileService = $sharedFileService;
        $this->quickReplyService = $quickReplyService;
        $this->imageUploadService = $imageUploadService;
        $this->autoReplyService = $autoReplyService;
        $this->memberService = $memberService;
    }

    /**
     * 聊天頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $user = Auth::user();
        if (!$user->hasPermission('telegram_chat.reply')
            && !$user->hasPermission('telegram_chat.assign')
            && !$user->hasPermission('telegram_chat.broadcast')) {
            abort(403);
        }

        return view('admin.telegram-chat.index', [
            // 沒設定 Claude 憑證時，前端要把自動回覆勾選框停用並提示去全域設定
            'autoReplyAvailable' => $this->autoReplyService->isAvailable(),
        ]);
    }

    /**
     * Ajax 切換對話的自動回覆開關
     *
     * @param ToggleAutoReplyRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxToggleAutoReply(ToggleAutoReplyRequest $request)
    {
        $params = $request->validated();

        try {
            $group = $this->autoReplyService->toggle((int) $params['group_id'], (bool) $params['enabled']);

            return response()->json(['auto_reply' => (bool) $group->auto_reply]);
        } catch (\RuntimeException $e) {
            // 群組不存在、或還沒設定 Claude 憑證
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('自動回覆開關切換失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('telegram_chat.msg.auto_reply_failed')], 500);
        }
    }

    /**
     * Ajax 取得對話的「不自動回覆」名單與發言過的人
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxIgnoreMembers(Request $request)
    {
        $params = ['group_id' => (int) $request->input('group_id')];

        $panel = $this->memberService->getPanel($params['group_id']);

        return response()->json([
            'ignored' => GroupMemberResource::collection($panel['ignored']),
            'recent'  => GroupMemberResource::collection($panel['recent']),
            // 清單標題要寫「近 N 天」，天數是後端設定的，不讓前端自己寫死一份
            'recent_days' => (int) config('constants.TELEGRAM.IGNORE.RECENT_DAYS'),
        ]);
    }

    /**
     * Ajax 切換成員的「不自動回覆」狀態
     *
     * @param ToggleIgnoreMemberRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxToggleIgnore(ToggleIgnoreMemberRequest $request)
    {
        $params = $request->validated();
        $actions = config('constants.TELEGRAM.IGNORE.ACTION');
        $groupId = (int) Arr::get($params, 'group_id', 0);
        $action = Arr::get($params, 'action');

        try {
            if ($action === $actions['RESTORE']) {
                $this->memberService->restore($groupId, (int) Arr::get($params, 'member_id', 0));

                return response()->json(['message' => trans('telegram_chat.msg.ignore_restored')]);
            }

            if ($action === $actions['ADD']) {
                $this->memberService->ignoreByUsername(
                    $groupId,
                    Arr::get($params, 'username'),
                    Arr::get($params, 'note'),
                    (int) Auth::id()
                );

                return response()->json(['message' => trans('telegram_chat.msg.ignore_added')]);
            }

            $this->memberService->ignore($groupId, (int) Arr::get($params, 'member_id', 0), (int) Auth::id());

            return response()->json(['message' => trans('telegram_chat.msg.ignore_added')]);
        } catch (\RuntimeException $e) {
            // 找不到成員、username 不合法、或已經在名單裡
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('忽略名單更新失敗', [
                'group_id' => $groupId,
                'action'   => $action,
                'error'    => $e->getMessage(),
                'user_id'  => Auth::id(),
            ]);

            return response()->json(['message' => trans('telegram_chat.msg.ignore_failed')], 500);
        }
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

        // 點進對話自動標為已讀
        $this->chatService->markAsRead($groupId);

        // 被設為不自動回覆的人，訊息上要標出來讓客服知道這則要自己回。
        // 跟著訊息一起回，不另開路由 —— 標籤是狀態揭露，沒有管理權限的人也該看得到
        return TelegramMessageResource::collection($messages)
            ->additional(['ignored_names' => $this->memberService->getIgnoredNames($groupId)]);
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
                Auth::user()->nickname,
                ['reply_to_id' => $params['reply_to_id'] ?? null]
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
                ['image_url' => $imageUrl]
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
     * Ajax 發送檔案訊息（圖片以外）
     *
     * @param SendFileRequest $request
     * @return \Illuminate\Http\JsonResponse|TelegramMessageResource
     */
    public function ajaxSendFile(SendFileRequest $request)
    {
        $params = $request->validated();

        try {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();

            // 檔名要能看出是什麼檔案，用 uploadKeepName 而非 uniqid 命名
            $filePath = $this->imageUploadService->uploadKeepName($file, 'telegram');

            $message = $this->chatService->sendFileReply(
                (int) $params['group_id'],
                $filePath,
                $originalName,
                $params['caption'] ?? null,
                Auth::id(),
                Auth::user()->nickname
            );

            return new TelegramMessageResource($message);
        } catch (\Exception $e) {
            Log::error('Telegram 檔案發送失敗', [
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
            'emoji'      => 'required|string|max:30',
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

    /**
     * Ajax 廣播正在輸入
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTyping(Request $request)
    {
        $params = $request->only(['group_id']);

        event(new TelegramTyping(
            (int) $params['group_id'],
            Auth::user()->nickname
        ));

        return response()->json(['ok' => true]);
    }

    /**
     * Ajax 刪除對話紀錄（清除群組訊息）
     *
     * @param \App\Models\TelegramGroup $group
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteConversation(\App\Models\TelegramGroup $group)
    {
        try {
            $count = $this->chatService->deleteConversation($group->id);

            return response()->json(['message' => "已刪除 {$count} 筆對話紀錄"]);
        } catch (\Exception $e) {
            Log::error('對話紀錄刪除失敗', ['error' => $e->getMessage(), 'group_id' => $group->id]);

            return response()->json(['message' => '刪除失敗'], 500);
        }
    }

    /**
     * Ajax 取得文件區檔案列表（Telegram 聊天選檔用）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSharedFiles()
    {
        $files = $this->sharedFileService->getFilesForTelegram(Auth::id());

        return response()->json($files);
    }

    /**
     * Ajax 取得快速回覆選單（客服選類別 → 問題 → 答案）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxQuickReplies()
    {
        $quickReplies = $this->quickReplyService->getForChat();

        return response()->json($quickReplies);
    }

    /**
     * Ajax 從文件區傳送檔案到 Telegram 群組
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSendDocument(Request $request)
    {
        $params = $request->validate([
            'group_id' => 'required|integer',
            'file_id'  => 'required|integer',
            'caption'  => 'nullable|string|max:1024',
        ]);

        try {
            $result = $this->chatService->sendDocumentFromSharedFile(
                (int) $params['group_id'],
                (int) $params['file_id'],
                $params['caption'] ?? null,
                Auth::id()
            );

            if (!$result) {
                return response()->json(['message' => '傳送失敗'], 500);
            }

            return response()->json(['message' => '檔案已傳送']);
        } catch (\Exception $e) {
            Log::error('Telegram 傳送文件失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '傳送失敗'], 500);
        }
    }
}
