<?php

/*
|--------------------------------------------------------------------------
| Reusable Validation Rule Fragments
|--------------------------------------------------------------------------
|
| Registry of reusable rule fragments shared across Form Requests. Each
| feature tops up this file with its own top-level group; Requests merge
| the base fragment here with contextual pieces (e.g. a `unique` rule
| with an `ignore` clause on update).
|
*/

return [

    'account' => [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255'],
        'password' => ['required', 'string', 'min:8'],
    ],

    'role' => [
        'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
        'display_name' => ['required', 'string', 'max:100'],
    ],

];
