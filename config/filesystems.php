<?php

return [
    'default' => env('FILESYSTEM_DISK', 'public'),
    'disks' => [
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],
       'r2' => [
          'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => env('R2_REGION', 'auto'),
    'bucket' => env('R2_BUCKET'),
    'url' => env('R2_URL'),
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => env(
        'R2_USE_PATH_STYLE_ENDPOINT',
        true
    ),
    'throw' => true,
],
    ],
];
