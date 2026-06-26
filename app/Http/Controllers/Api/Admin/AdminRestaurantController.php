<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;

class AdminRestaurantController extends Controller
{
    public function index()
    {
        return RestaurantResource::collection(Restaurant::with('owner')->latest()->paginate(20));
    }
}
