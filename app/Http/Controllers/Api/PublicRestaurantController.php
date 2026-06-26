<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuItemResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\RestaurantService;

class PublicRestaurantController extends Controller
{
    public function __construct(private readonly RestaurantService $restaurantService)
    {
    }

    public function index()
    {
        return RestaurantResource::collection($this->restaurantService->publicListings());
    }

    public function show(Restaurant $restaurant)
    {
        return new RestaurantResource($this->restaurantService->publicDetails($restaurant));
    }

    public function menuItems(Restaurant $restaurant)
    {
        return MenuItemResource::collection($this->restaurantService->publicMenuItems($restaurant));
    }
}
