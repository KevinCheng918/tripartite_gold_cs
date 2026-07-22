# tripartite_gold_cs

## 後台範本
php : 7.4.*

laravel : v8.83.29

node : 10.16.*

## install sop:
1. 檢查php版本為 7.3.* , node版本為 10.16.*
2. 執行指令 composer install
3. 執行指令 npm install
4. 執行指令 npm run dev
5. 從範本建立.evn文件,  cp .env.example .env
6. .env文件設置相關參數(ex. db, redis cache...)
7. php artisan migrate:install
8. php artisan migrate
9. php artisan db:seed --class=CreateAdminSeeder
10. php artisan db:seed --class=SetPermissionSeeder
11. php artisan db:seed --class=CategorySeeder
