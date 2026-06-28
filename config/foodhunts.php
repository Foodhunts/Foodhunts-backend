<?php

return [
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:8000')),
    'default_delivery_fee' => (float) env('FOODHUNTS_DEFAULT_DELIVERY_FEE', 0),
    'service_fee_rate' => (float) env('FOODHUNTS_SERVICE_FEE_RATE', 0.05),
];
