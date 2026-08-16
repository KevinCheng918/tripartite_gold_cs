<?php

namespace App\Presenters;

/**
 * 數字格式化 Presenter
 *
 * 移除尾部無意義的零，例如：
 *   100.0000 → 100
 *   32.0500  → 32.05
 *   3205.00  → 3205
 *   100.5010 → 100.501
 */
class NumberPresenter
{
    /**
     * 格式化數字，移除尾部多餘的零
     *
     * @param float|string|null $value       數值
     * @param int               $maxDecimals 最大保留小數位數
     * @return string
     */
    public static function trimZeros($value, $maxDecimals = 4)
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $formatted = number_format((float) $value, $maxDecimals, '.', '');

        // 有小數點時，移除尾部的 0，若小數部分全為 0 則連小數點也移除
        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }

        return $formatted;
    }
}
