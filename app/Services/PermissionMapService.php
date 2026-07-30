<?php

namespace App\Services;

/**
 * 權限地圖 Service
 *
 * 讀取 config/permissionMap.php 的權限定義，
 * 提供分組查詢、關鍵字列表、翻譯標籤等功能。
 */
class PermissionMapService
{
    /**
     * 取得分組的權限關鍵字（原始格式）
     *
     * @return array
     */
    public function getGroupedKeywords()
    {
        return config('permissionMap', []);
    }

    /**
     * 取得所有已註冊的權限關鍵字（扁平化）
     *
     * @return array
     */
    public function getAllKeywords()
    {
        $keywords = [];

        foreach ($this->getGroupedKeywords() as $group) {
            $keywords = array_merge($keywords, array_keys($group['keywords']));
        }

        return $keywords;
    }

    /**
     * 檢查關鍵字是否有效
     *
     * @param string $keyword
     * @return bool
     */
    public function isValidKeyword($keyword)
    {
        return in_array($keyword, $this->getAllKeywords(), true);
    }

    /**
     * 取得分組的權限關鍵字（含翻譯後的 label），供前端權限設定 UI 使用
     *
     * @return array
     */
    public function getGroupedKeywordsWithLabels()
    {
        $result = [];

        foreach ($this->getGroupedKeywords() as $groupKey => $group) {
            $keywords = [];

            foreach ($group['keywords'] as $keyword => $langKey) {
                $keywords[] = [
                    'keyword' => $keyword,
                    'label' => trans($langKey),
                ];
            }

            $result[] = [
                'group' => $groupKey,
                'label' => trans($group['label']),
                'keywords' => $keywords,
            ];
        }

        return $result;
    }
}
