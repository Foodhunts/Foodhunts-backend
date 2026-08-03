<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuItemResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\RestaurantService;
use Illuminate\Http\Request;

class PublicRestaurantController extends Controller
{
    public function __construct(private readonly RestaurantService $restaurantService)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return RestaurantResource::collection($this->restaurantService->publicListings(
            $validated['search'] ?? null,
            (int) ($validated['per_page'] ?? 20),
        ));
    }

    public function show(Restaurant $restaurant)
    {
        return new RestaurantResource($this->restaurantService->publicDetails($restaurant));
    }

    public function menuItems(Request $request, Restaurant $restaurant)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return MenuItemResource::collection($this->restaurantService->publicMenuItems(
            $restaurant,
            (int) ($validated['per_page'] ?? 100),
        ));
    }

    public function menuItem(\App\Models\MenuItem $menuItem)
    {
        return response()->json(['success' => true, 'message' => 'Menu item fetched successfully', 'data' => new \App\Http\Resources\MenuItemResource($menuItem->load('menu'))]);
    }

    public function activeAds()
    {
        return response()->json(['success' => true, 'message' => 'Active advertisements fetched successfully', 'data' => []]);
    }
}
