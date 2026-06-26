<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }
}
