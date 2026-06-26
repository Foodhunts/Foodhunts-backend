# Foodhunts Backend

Laravel API foundation for the Foodhunts mobile, restaurant, admin, and courier apps.

## Status

- Scaffolded API structure
- Sanctum auth flow
- Public health endpoint
- Core domain models, controllers, services, requests, resources
- Transactional order creation flow
- Supabase migration plan drafted

## Run locally

1. Install PHP 8.2+, Composer, PostgreSQL, and Redis.
2. Copy `.env.example` to `.env` and configure database and provider keys.
3. Run `composer install`.
4. Run `php artisan key:generate`.
5. Run `php artisan migrate`.
6. Start the app with `php artisan serve`.

## Health check

- `GET /api/health`

## Notes

- This scaffold runs beside the existing Next.js/Supabase apps.
- Supabase is not removed yet.
- The first migrations focus on the core backend foundation only.
