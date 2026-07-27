<?php

/*
|--------------------------------------------------------------------------
| Initial Admin Account
|--------------------------------------------------------------------------
|
| Credentials used by CreateAdminSeeder to seed the first admin account.
| Read via config() rather than env() directly, since env() calls made
| outside config/*.php files return null once config is cached
| (php artisan config:cache / optimize).
|
*/

return [

    'email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'password' => env('ADMIN_PASSWORD', 'password'),

    'new_admin_email' => env('NEW_ADMIN_EMAIL', 'newadmin@example.com'),
    'new_admin_password' => env('NEW_ADMIN_PASSWORD'),

];
