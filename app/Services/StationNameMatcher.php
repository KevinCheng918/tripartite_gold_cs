<?php

namespace App\Services;

/**
 * 把人講的站台名稱對到真正的站台
 *
 * 四支 Telegram 指令都要回答同一個問題：「他說的這個名字是哪一個站台？」
 * 這支就是那個唯一的答案 —— 各自寫一份的話，同一個站台會變成在某支指令
 * 找得到、在另一支找不到。
 *
 * ⚠ **`_`、`-`、空白視為同一件事**（需求方 2026-10-13 的三句話裡，
 * 同一個站台寫了 `LV_UAT` 也寫了 `lv-uat`）。大小寫一樣不計。
 *
 * ⚠ **正規化之後仍然要完全相等，不做模糊比對。** `LV` 不會命中 `LV_UAT`
 * —— 猜錯會讓人拿著別的站台的數字做判斷。
 */
class StationNameMatcher
{
    /**
     * 從候選站台裡找出完全相符的那一個
     *
     * @param string                                   $name     使用者講的名字
     * @param \Illuminate\Support\Collection|iterable  $stations 候選（要有 `name` 屬性）
     * @return mixed|null 命中的那一筆，沒有就 null
     */
    public function match($name, $stations)
    {
        $needle = $this->normalize($name);

        if (blank($needle)) {
            return null;
        }

        foreach ($stations as $station) {
            if ($this->normalize($station->name) === $needle) {
                return $station;
            }
        }

        return null;
    }

    /**
     * 這串字裡出現了哪些候選站台的名稱
     *
     * 用來分辨「他一次講了兩個站台」與「他講的名字不存在」——
     * 兩種都是完全相符找不到，但要回的話不一樣。
     *
     * ⚠ **只用來數個數，不能拿來代替 `match()`。** 假設站台有 `T01` 沒有
     * `T011`，使用者問 `T011` 時這裡會「包含 T01」—— 直接拿去查就回了
     * 另一個站台的數字。所以命中一個時呼叫端仍要走「找不到」。
     *
     * @param string                                  $text
     * @param \Illuminate\Support\Collection|iterable $stations
     * @return array<string> 站台名稱原文
     */
    public function mentioned($text, $stations)
    {
        $haystack = $this->normalize($text);

        if (blank($haystack)) {
            return [];
        }

        $names = [];

        foreach ($stations as $station) {
            $name = (string) $station->name;
            $needle = $this->normalize($name);

            if (blank($needle)) {
                continue;
            }

            if (mb_strpos($haystack, $needle) !== false) {
                $names[] = $name;
            }
        }

        return $this->dropContained($names);
    }

    /**
     * 排掉「被別的命中名稱整個包住」的那些
     *
     * ⚠ 同時有 `T01` 與 `T011` 兩個站台時，字串 "T011" 會同時包含兩者 ——
     * 不排掉就會被數成「講了兩個站台」。長的先比，被它包住的短名稱不算。
     *
     * @param array<string> $names
     * @return array<string>
     */
    private function dropContained(array $names)
    {
        usort($names, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        $kept = [];

        foreach ($names as $name) {
            $contained = false;

            foreach ($kept as $longer) {
                if (mb_strpos($this->normalize($longer), $this->normalize($name)) !== false) {
                    $contained = true;
                    break;
                }
            }

            if (!$contained) {
                $kept[] = $name;
            }
        }

        return $kept;
    }

    /**
     * 正規化：轉小寫、去頭尾空白、`_` 與 `-` 與空白一律拿掉
     *
     * `LV_UAT` / `lv-uat` / `LV UAT` / `lvuat` 都會變成 `lvuat`。
     *
     * @param string|null $value
     * @return string
     */
    private function normalize($value)
    {
        $value = mb_strtolower(trim((string) $value));

        return str_replace(['_', '-', ' ', '　'], '', $value);
    }
}
