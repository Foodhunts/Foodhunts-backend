<?php

return [
    'driver' => env('MEDIA_STORAGE_DRIVER', 'legacy'),
    'public_base_url' => env('R2_PUBLIC_URL'),
    'private_url_ttl_minutes' => (int) env('MEDIA_PRIVATE_URL_TTL_MINUTES', 10),
    'limits' => [
        'public_image_kb' => (int) env('MEDIA_PUBLIC_IMAGE_MAX_KB', 5120),
        'private_document_kb' => (int) env('MEDIA_PRIVATE_DOCUMENT_MAX_KB', 10240),
    ],
];
