<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;

class AdminUserController extends Controller
{
    public function index()
    {
        return UserResource::collection(User::latest()->paginate(20));
    }
}
