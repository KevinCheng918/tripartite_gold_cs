<?php

namespace App\Services;

class PermissionMapService
{
    /**
     * @return array<string, array{label: string, keywords: array<string, string>}>
     */
    public function getGroupedKeywords(): array
    {
        return config('permissionMap', []);
    }

    /**
     * @return array<int, string> every registered permission keyword, flattened
     */
    public function getAllKeywords(): array
    {
        $keywords = [];

        foreach ($this->getGroupedKeywords() as $group) {
            $keywords = array_merge($keywords, array_keys($group['keywords']));
        }

        return $keywords;
    }

    public function isValidKeyword(string $keyword): bool
    {
        return in_array($keyword, $this->getAllKeywords(), true);
    }

    /**
     * Grouped keywords with their translated labels, for the "assign permissions" UI.
     *
     * @return array<int, array{group: string, label: string, keywords: array<int, array{keyword: string, label: string}>}>
     */
    public function getGroupedKeywordsWithLabels(): array
    {
        $result = [];

        foreach ($this->getGroupedKeywords() as $groupKey => $group) {
            $keywords = [];

            foreach ($group['keywords'] as $keyword => $langKey) {
                $keywords[] = [
                    'keyword' => $keyword,
                    'label' => __($langKey),
                ];
            }

            $result[] = [
                'group' => $groupKey,
                'label' => __($group['label']),
                'keywords' => $keywords,
            ];
        }

        return $result;
    }
}
