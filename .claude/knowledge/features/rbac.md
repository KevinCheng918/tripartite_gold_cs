# 帳號權限（RBAC）

## 現況

尚未實作。目前僅有 Laravel 預設 `User` model 與 auth 骨架，無角色/權限資料表。

## 需求

- 多帳號，每個帳號有各自權限
- 權限需能細分到功能層級（至少要能區分「一般客服」與「有權限審核排班的帳號」，見 [[scheduling]]）

## 待釐清

- 角色是固定幾種（如：客服 / 班別審核主管 / 系統管理員）還是自訂權限群組？
- 是否比照舊版 PROMPTS.md 慣例，用 `config/permissionMap.php` + permission keyword + route 綁定的模式？
