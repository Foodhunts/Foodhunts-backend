<?php

return [
    'url' => rtrim((string) env('SUPABASE_URL', ''), '/'),

    'publishable_key' => env(
        'SUPABASE_PUBLISHABLE_KEY',
        env('SUPABASE_ANON_KEY')
    ),

    'auth_timeout' => (int) env('SUPABASE_AUTH_TIMEOUT', 5),

    'auth_connect_timeout' => (int) env(
        'SUPABASE_AUTH_CONNECT_TIMEOUT',
        3
    ),
];
