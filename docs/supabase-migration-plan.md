# Supabase Migration Plan

## Phase 1

- Identity and auth
- Restaurants and public listing
- Menus and menu items
- Addresses
- Orders and order items
- Payments and payment attempts
- Customer wallet and wallet transactions
- Push token registration

## Phase 2

- Promotions
- Ratings and reviews
- Courier dispatch and tracking
- Buy-for-me requests
- Referrals and referral rewards

## Phase 3

- Admin analytics
- Refunds and reversals
- Payout orchestration
- R2/S3 media storage
- Notification delivery logs

## Migration rule

- Do not recreate legacy Supabase tables all at once.
- Move one bounded context at a time.
- Keep the Next.js app on Supabase until each API surface is replaced.
