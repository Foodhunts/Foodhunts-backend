<?php

use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminBuyForMeController;
use App\Http\Controllers\Api\Admin\AdminPaymentAttemptController;
use App\Http\Controllers\Api\Admin\AdminReferralController;
use App\Http\Controllers\Api\Admin\AdminRestaurantController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\BuyForMeController as CustomerBuyForMeController;
use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\ReferralController as CustomerReferralController;
use App\Http\Controllers\Api\FeatureFlagController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\PublicRestaurantController;
use App\Http\Controllers\Api\PublicApi\BuyForMeController as PublicBuyForMeController;
use App\Http\Controllers\Api\Restaurant\RestaurantOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/feature-flags', [FeatureFlagController::class, 'index']);

Route::get('/public/buy-for-me/{token}', [PublicBuyForMeController::class, 'show']);
Route::post('/public/buy-for-me/{token}/pay', [PublicBuyForMeController::class, 'pay'])
    ->middleware('throttle:20,1');

Route::prefix('auth')->group(function (): void {
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });
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
    Route::get('/referrals/me', [CustomerReferralController::class, 'me']);
    Route::post('/referrals/apply', [CustomerReferralController::class, 'apply']);

    Route::prefix('customer')->group(function (): void {
        Route::apiResource('addresses', AddressController::class)->except(['show']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::get('/buy-for-me', [CustomerBuyForMeController::class, 'index']);
        Route::post('/buy-for-me', [CustomerBuyForMeController::class, 'store']);
        Route::get('/buy-for-me/{buyForMeRequest}', [CustomerBuyForMeController::class, 'show']);
        Route::post('/buy-for-me/{buyForMeRequest}/cancel', [CustomerBuyForMeController::class, 'cancel']);
    });

    Route::prefix('restaurant')->middleware('role:restaurant_owner')->group(function (): void {
        Route::get('/orders', [RestaurantOrderController::class, 'index']);
        Route::get('/orders/{order}', [RestaurantOrderController::class, 'show']);
        Route::post('/orders/{order}/accept', [RestaurantOrderController::class, 'accept']);
        Route::post('/orders/{order}/mark-preparing', [RestaurantOrderController::class, 'markPreparing']);
        Route::post('/orders/{order}/mark-ready', [RestaurantOrderController::class, 'markReady']);
    });

    Route::prefix('payments')->group(function (): void {
        Route::post('/initialize', [PaymentController::class, 'initialize'])
            ->middleware('throttle:20,1');
        Route::post('/verify', [PaymentController::class, 'verify']);
    });

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/restaurants', [AdminRestaurantController::class, 'index']);
        Route::post('/wallets/{user}/credit', [AdminWalletController::class, 'credit']);
        Route::post('/wallets/{user}/debit', [AdminWalletController::class, 'debit']);
        Route::get('/referrals', [AdminReferralController::class, 'index']);
        Route::get('/referral-rewards', [AdminReferralController::class, 'rewards']);
        Route::get('/buy-for-me-requests', [AdminBuyForMeController::class, 'index']);
        Route::get('/buy-for-me-requests/{buyForMeRequest}', [AdminBuyForMeController::class, 'show']);
    });
});

/*
 * Laravel V2 contract. These aliases intentionally coexist with the original
 * routes above so existing clients remain untouched during migration.
 */
Route::prefix('v2')->group(function (): void {
    Route::get('/home', [PublicRestaurantController::class, 'index']);
    Route::get('/restaurants', [PublicRestaurantController::class, 'index']);
    Route::get('/restaurants/{restaurant}', [PublicRestaurantController::class, 'show']);
    Route::get('/restaurants/{restaurant}/menu', [PublicRestaurantController::class, 'menuItems']);
    Route::get('/menu-items/{menuItem}', [PublicRestaurantController::class, 'menuItem']);
    Route::get('/ads/active', [PublicRestaurantController::class, 'activeAds']);

    Route::prefix('auth')->group(function (): void {
        Route::middleware('throttle:20,1')->group(function (): void {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
        });
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:10,1');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:10,1');
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/profile', [AuthController::class, 'profile']);
        });
    });

    Route::get('/buy-for-me/{token}', [PublicBuyForMeController::class, 'show']);
    Route::post('/buy-for-me/{token}/initialize-payment', [PublicBuyForMeController::class, 'pay'])
        ->middleware('throttle:20,1');
    Route::post('/webhooks/paystack', [PaymentController::class, 'webhook']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::apiResource('addresses', AddressController::class)
            ->except(['show'])
            ->names([
                'index' => 'v2.addresses.index',
                'store' => 'v2.addresses.store',
                'update' => 'v2.addresses.update',
                'destroy' => 'v2.addresses.destroy',
            ]);
        Route::post('/addresses/default', [AddressController::class, 'setDefault']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::get('/orders/{order}/tracking', [OrderController::class, 'tracking']);
        Route::get('/referrals/dashboard', [CustomerReferralController::class, 'me']);
        Route::get('/referrals/invited-users', [CustomerReferralController::class, 'me']);
        Route::post('/buy-for-me', [CustomerBuyForMeController::class, 'store']);
        Route::get('/buy-for-me/{token}/status', [CustomerBuyForMeController::class, 'status']);
        Route::post('/push-tokens', [PushTokenController::class, 'store']);
        Route::delete('/push-tokens/{pushToken}', [PushTokenController::class, 'destroy']);

        Route::prefix('payments')->group(function (): void {
            Route::post('/paystack/initialize', [PaymentController::class, 'initialize'])
                ->middleware('throttle:20,1');
            Route::post('/paystack/verify', [PaymentController::class, 'verify']);
        });

        Route::prefix('store')->middleware('role:restaurant_owner')->group(function (): void {
            Route::get('/orders', [RestaurantOrderController::class, 'index']);
            Route::get('/orders/{order}', [RestaurantOrderController::class, 'show']);
            Route::post('/orders/{order}/accept', [RestaurantOrderController::class, 'accept']);
            Route::post('/orders/{order}/preparing', [RestaurantOrderController::class, 'markPreparing']);
            Route::post('/orders/{order}/ready', [RestaurantOrderController::class, 'markReady']);
        });

        Route::prefix('admin')->middleware('admin')->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/restaurants', [AdminRestaurantController::class, 'index']);
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
            Route::post('/wallets/{user}/credit', [AdminWalletController::class, 'credit']);
            Route::post('/wallets/{user}/reverse', [AdminWalletController::class, 'debit']);
            Route::get('/payment-attempts', [AdminPaymentAttemptController::class, 'index']);
        });
    });
});
