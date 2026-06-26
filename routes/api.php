<?php

use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminRestaurantController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\PublicRestaurantController;
use App\Http\Controllers\Api\Restaurant\RestaurantOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::prefix('restaurants')->group(function (): void {
    Route::get('/', [PublicRestaurantController::class, 'index']);
    Route::get('/{restaurant}', [PublicRestaurantController::class, 'show']);
    Route::get('/{restaurant}/menu-items', [PublicRestaurantController::class, 'menuItems']);
});

Route::post('/payments/paystack/webhook', [PaymentController::class, 'webhook']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/push-tokens/register', [PushTokenController::class, 'store']);

    Route::prefix('customer')->group(function (): void {
        Route::apiResource('addresses', AddressController::class)->except(['show']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
    });

    Route::prefix('restaurant')->middleware('role:restaurant_owner')->group(function (): void {
        Route::get('/orders', [RestaurantOrderController::class, 'index']);
        Route::get('/orders/{order}', [RestaurantOrderController::class, 'show']);
        Route::post('/orders/{order}/accept', [RestaurantOrderController::class, 'accept']);
        Route::post('/orders/{order}/mark-preparing', [RestaurantOrderController::class, 'markPreparing']);
        Route::post('/orders/{order}/mark-ready', [RestaurantOrderController::class, 'markReady']);
    });

    Route::prefix('payments')->group(function (): void {
        Route::post('/initialize', [PaymentController::class, 'initialize']);
        Route::post('/verify', [PaymentController::class, 'verify']);
    });

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/restaurants', [AdminRestaurantController::class, 'index']);
        Route::post('/wallets/{user}/credit', [AdminWalletController::class, 'credit']);
        Route::post('/wallets/{user}/debit', [AdminWalletController::class, 'debit']);
    });
});
