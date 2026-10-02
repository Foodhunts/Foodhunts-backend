# CLAUDE.md — Foodhunts-backend (Laravel 11 API)

Part of the FoodHunts ecosystem; the full cross-repo map and hazards are in `../foodhint-admin/CLAUDE.md` — read its "ecosystem" section first.

## Role
Partially-built Laravel API meant to replace Supabase direct-access **one bounded context at a time** (`docs/supabase-migration-plan.md`). Supabase (project `uyzqofzhmsgafdwojqie`) is still the live source of truth for orders, payments and wallets. Today Laravel is used in production for **restaurant media uploads** (R2) called by store-web/store-mobile; the V2 auth/orders/wallet/admin surface is still `pending` in `docs/API_INVENTORY.md`.

## Commands
`composer install` · `php artisan serve` · `php artisan test` (PHPUnit; only `tests/Feature/PublicRestaurantVisibilityTest.php` exists) · `vendor/bin/pint` for style · `php artisan migrate` (never against prod without explicit approval). Health: `GET /api/health`.

## Layout
`routes/api.php` (legacy un-versioned routes + `/api/v2` aliases that must coexist — don't break existing clients) · `app/Http/Controllers/Api/{Admin,Customer,Restaurant,Public,PublicApi}` · `app/Services` (Order, Payment, Paystack, Wallet, PushNotification, Referral*, BuyForMe, FeatureFlag, Restaurant, Auth) · `app/Enums` · `app/Http/Middleware` (`admin`, `role:`) · `app/Support/ApiResponse.php` (`{success,message,data}` envelope).

## Rules
- Responses use the `ApiResponse` envelope; validate with Form Requests, shape output with Resources, add a feature test per endpoint.
- Auth is Sanctum; role gates via `role:restaurant_owner` / `admin` middleware. Restaurant ownership in prod is `restaurants.owner_id`.
- **`OrderStatus` enum here (`accepted`, `ready`, `completed`) differs from the Supabase/app vocabulary (`confirmed`, `ready_for_pickup`, …).** Reconcile before wiring any status endpoint to live data.
- Wallet contract: transaction `type` ∈ credit|debit; `category` nullable ∈ order_payment|refund|reversal|admin_credit. Paystack: verify signature + kobo amount, idempotent by reference. Money paths need transactions and tests.
- Media endpoints (`/api/v2/restaurants/{id}/media/logo|cover`, `/menu-items/{id}/media`) are consumed by other repos — keep their contract stable.
- Env keys are in `.env.example` (Paystack, R2, Expo push, mail, `FRONTEND_URL/ADMIN_URL/STORE_URL`). Never commit `.env` or print secrets.
