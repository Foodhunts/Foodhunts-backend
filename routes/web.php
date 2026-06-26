<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'Foodhunts API',
    'status' => 'ok',
]));
