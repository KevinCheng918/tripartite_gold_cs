<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 專案負責人
 *
 * 需求方 2026-10-30：「專案要有負責人，要有權限能夠修改、新增負責人，
 * 負責人只會有一位。」
 *
 * ⚠ 「只有一位」由**結構**保證 —— `project.owner_id` 是一個欄位，
 * 天生放不下第二個人。這支 Service 不必檢查「是不是已經有負責人了」。
 */
class ProjectOwnerService
{
    /** @var string 這支功能要的權限 */
    const PERMISSION = 'project.assign_owner';

    private $projectRepository;
    private $userRepository;

    public function __construct(
        ProjectRepository $projectRepository,
        UserRepository $userRepository
    ) {
        $this->projectRepository = $projectRepository;
        // 候選名單（只有參與這個專案的人）要從帳號那邊撈
        $this->userRepository = $userRepository;
    }

    /**
     * 指派／更換／清空負責人
     *
     * ⚠ **清空是合法操作**（`$ownerId` 傳 null）：負責人離職而還沒決定接手的人時，
     * 擺著一個已離職的名字比「未指派」更容易誤導。
     *
     * @param Project  $project
     * @param int|null $ownerId
     * @return Project
     *
     * @throws ValidationException 指派的人沒有參與這個專案
     */
    public function assign(Project $project, $ownerId)
    {
        if (blank($ownerId)) {
            return $this->projectRepository->update($project, ['owner_id' => null]);
        }

        $this->guardParticipation((int) $project->id, (int) $ownerId);

        Log::info('專案負責人異動', [
            'project_id' => $project->id,
            'from'       => $project->owner_id,
            'to'         => (int) $ownerId,
        ]);

        return $this->projectRepository->update($project, ['owner_id' => (int) $ownerId]);
    }

    /**
     * 擋掉「指派一個沒參與這個專案的人」
     *
     * 需求方 2026-10-30 裁示：擋下來，要求先去帳號管理勾選參與專案。
     *
     * 理由是 v3.44 起專案管理**只看得到自己有勾參與專案的專案** ——
     * 指派一個沒勾的人當負責人，他登入後看不到自己負責的那個專案，
     * 不會報錯，只會覺得「我明明是負責人，這頁卻是空的」。
     *
     * ⚠ **後端一定要再驗一次**，不能只靠下拉選單只列合格的人：
     * 下拉是前端的清單，直接打 API 照樣塞得進一個沒參與的 id。
     *
     * ⚠ 錯誤訊息要講**下一步**（去哪裡處理），只回「不可以指派」的話，
     * 被擋的人不知道要去帳號管理勾參與專案。
     *
     * @param int $projectId
     * @param int $ownerId
     * @return void
     *
     * @throws ValidationException
     */
    private function guardParticipation($projectId, $ownerId)
    {
        $candidates = $this->userRepository->getOwnerCandidatesByProject([$projectId]);
        $allowed = $candidates->get($projectId, collect());

        $owner = $allowed->first(function ($user) use ($ownerId) {
            return (int) $user->id === $ownerId;
        });

        if (filled($owner)) {
            return;
        }

        $reason = $this->rejectReason($ownerId);

        /*
         * ⚠ 這要留 Log：下拉**只列得出合格的人**，所以走到這裡的請求幾乎都是
         * 直接打 API 來的 —— 可能是前端沒跟上（名單過期），也可能是有人在試
         * 繞過限制。兩種都值得留痕。
         */
        Log::warning('專案負責人指派被擋', [
            'project_id' => $projectId,
            'owner_id'   => $ownerId,
            'reason'     => $reason,
        ]);

        throw ValidationException::withMessages([
            'owner_id' => [trans("project.{$reason}")],
        ]);
    }

    /**
     * 被擋下來的原因（只決定「講哪一句」，不決定合不合格）
     *
     * ⚠ **合不合格由候選名單決定，這一支不參與判斷** —— 它只是為了把
     * 「管理者不可以當負責人」跟「尚未參與本專案」分開講。
     * 把判斷搬進來就會變成同一條規則的第二份實作，而分岔的那一邊就是漏洞。
     *
     * ⚠ 這兩句**必須分得開**：處理方式完全不同 —— 前者要換一個人，
     * 後者是去帳號管理把他加進參與專案。回同一句的話，被擋的人會跑去
     * 幫管理者勾參與專案，然後發現還是不行。
     *
     * ⚠ 走到這裡已經是錯誤路徑（下拉選不出不合格的人），多一趟查詢可以接受；
     * 為此另開一支精簡版 finder 反而是多一份要維護的東西。
     *
     * @param int $ownerId
     * @return string 語系 key（不含 `project.` 前綴）
     */
    private function rejectReason($ownerId)
    {
        $owner = $this->userRepository->find($ownerId);

        if (filled($owner) && $owner->isAdmin()) {
            return 'owner_not_allowed_admin';
        }

        /*
         * ⚠ 這一句同時涵蓋「沒參與這個專案」與「帳號已停用／離職」——
         * 候選名單本來就只收啟用中又有參與的人。兩種都講同一句會不精確，
         * 但實務上從下拉選的人不可能踩到第二種（只有直接打 API 才會）。
         */
        return 'owner_not_participant';
    }

    /**
     * 每個專案可以指派的人（變更負責人的下拉用）
     *
     * @param array $projectIds
     * @return \Illuminate\Support\Collection
     */
    public function candidatesFor(array $projectIds)
    {
        return $this->userRepository->getOwnerCandidatesByProject($projectIds);
    }

    /**
     * 可以指派的人（全部，不分專案）—— **新增專案的下拉用**
     *
     * ⚠ 新增那條路徑不能用 `candidatesFor()`：專案是存檔那一刻才建立的，
     * 在那之前沒有人勾得了「參與它」，逐專案篩的結果只會剩管理者。
     * 理由與後續補救見 `UserRepository::getAssignableForOwner()`。
     *
     * @return \Illuminate\Support\Collection
     */
    public function assignableUsers()
    {
        return $this->userRepository->getAssignableForOwner();
    }
}
