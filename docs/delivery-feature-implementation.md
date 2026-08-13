# Foodhunts Delivery Feature — Implementation Plan

**Status:** Draft for review
**Owner:** Backend team (Laravel) + Mobile team (Expo)
**Scope:** End-to-end delivery: order → rider dispatch → trip execution → delivery confirmation → rider payout
**Reference material:** dzpatch-v2 / dzpatch-v3 architecture docs (trip state machine, dispatch safety invariants, tracking model)

---

## Table of Contents

1. [Goal & Success Criteria](#1-goal--success-criteria)
2. [Current State Analysis](#2-current-state-analysis)
3. [Architecture Decision: Where Delivery Lives](#3-architecture-decision-where-delivery-lives)
4. [Data Model (New Tables & Migrations)](#4-data-model-new-tables--migrations)
5. [Trip State Machine](#5-trip-state-machine)
6. [API Contract (New Endpoints)](#6-api-contract-new-endpoints)
7. [Dispatch Engine](#7-dispatch-engine)
8. [Live Tracking Model](#8-live-tracking-model)
9. [Money Flow & Rider Payouts](#9-money-flow--rider-payouts)
10. [Rider App (Expo)](#10-rider-app-expo)
11. [Admin & Store App Changes](#11-admin--store-app-changes)
12. [Customer App Changes](#12-customer-app-changes)
13. [Phased Implementation Roadmap](#13-phased-implementation-roadmap)
14. [What to Copy From dzpatch / What to Skip](#14-what-to-copy-from-dzpatch--what-to-skip)
15. [Risks, Gotchas & Open Questions](#15-risks-gotchas--open-questions)

---

## 1. Goal & Success Criteria

**Feature:** A customer places an order on the Foodhunts app and a rider delivers it to their address, with live status tracking, delivery confirmation, and a payout to the rider.

**Success criteria (v1):**

- [ ] Customer can place an order with a delivery address and see a distance-based delivery fee at checkout (already exists ✅)
- [ ] Order lifecycle is fully tracked: placed → confirmed → preparing → ready → rider assigned → rider en route → picked up → delivered
- [ ] Customer sees live order status + rider location during the trip (poll-based, not streaming)
- [ ] Rider has an app: signup, KYC approval, job feed, accept, navigate, confirm pickup/dropoff with a delivery code
- [ ] Rider earnings are recorded per delivery and visible to the rider
- [ ] Admin can approve riders, reassign deliveries, and configure delivery pricing
- [ ] All money movement is idempotent and auditable (no double-charge, no double-payout)

**Non-goals (v1):** bidding/counter-offers, fleet management, multi-drop orders, scheduled deliveries, live chat with riders, DVA (dedicated virtual accounts), partner APIs.

---

## 2. Current State Analysis

### 2.1 What exists today (verified in code)

| Capability | Where | Status |
|---|---|---|
| Order creation (wallet/card) | Supabase Edge Function `process-order` → RPC `create_order_checkout` | ✅ Live (customer app) |
| Order creation (parallel) | Laravel `OrderService::createOrderFromCart` + `POST /v2/orders` | ✅ Built, apps not migrated |
| Delivery fee (distance-based) | `process-order` edge function: haversine, base 1200–1500₦, free 4 km, 300₦/km, min fee, surge | ✅ Live — global single setting |
| Delivery fee (Laravel) | `delivery_pricing_settings` table (base_fee, per_km_fee, minimum_fee) | ⚠️ Table exists, unused by quote logic (`quoteCart` uses `config('foodhunts.default_delivery_fee')`) |
| Delivery address | `addresses` (lat/lng) → `orders.delivery_address_id` | ✅ Live |
| Order statuses (Laravel) | `pending, accepted, preparing, ready, out_for_delivery, delivered, completed, rejected, cancelled` | ✅ Enum exists |
| Order statuses (Supabase) | `pending, confirmed, rejected, preparing, out_for_delivery, delivered, cancelled` | ✅ Live |
| Status history | `order_status_history` (Laravel) + tracking events (Supabase) | ✅ Tables exist; Laravel `/tracking` returns empty history (stub) |
| Courier concept | `orders.courier` jsonb `{name, phone_number}` — store staff type it manually | ⚠️ Not a real entity |
| ETA | Hardcoded `+45 minutes` at checkout | ⚠️ Not dispatch-aware |
| Wallet + Paystack | `wallets`, `wallet_transactions` (category/direction/status), `paystack_events`, webhook handler | ✅ Live |
| Push notifications | `push_tokens`, `PushNotificationService`, Supabase push trigger + edge functions | ✅ Live |
| Auth | Supabase Auth; Laravel verifies Supabase JWTs (`AuthenticateWithSupabase` middleware) | ✅ Live |

### 2.2 What's missing entirely

- **Riders** — no rider entity, onboarding, KYC, documents, or availability
- **Dispatch** — no assignment engine, no "one active trip per rider" enforcement
- **Trip state machine** — no rider-side transitions (en route to pickup, arrived, picked up, delivered)
- **Live tracking** — no rider location capture, no customer tracking endpoint
- **Rider money** — no earnings ledger, no delivery payout, no commission split
- **Delivery confirmation** — no delivery code / proof-of-delivery
- **Rider app** — none of the four apps is a rider app

### 2.3 The dual-backend situation (critical context)

Two parallel backends exist today:

```
foodhunt-mobile (customer)      ──┐
foodhunt-store-mobile (store)   ──┼──►  Supabase (DB + Edge Functions)  ◄── source of truth today
foodhunt-store-web (store web)  ──┤
foodhint-admin (admin)          ──┘
Foodhunts-backend (Laravel)     ──►  own DB + API (routes /v2/*)  ── "coexists so existing clients remain untouched"
```

The Laravel `/v2` route group is explicitly designed as the migration target ("Laravel V2 contract… intentionally coexist with the original routes so existing clients remain untouched during migration"). **The delivery feature must be built on Laravel only** — never as Supabase RPCs/triggers. That is the exact path dzpatch v2 took (230 RPCs, 40 triggers, RLS, pg_net push) and then spent an entire v3 rewrite escaping.

---

## 3. Architecture Decision: Where Delivery Lives

### Decision: Laravel is the source of truth for orders + delivery

- [x] **Riders, dispatch, trips, tracking, rider payouts** → Laravel (new tables + services below)
- [x] **Order checkout** → migrate to Laravel `OrderService` (port `create_order_checkout` safety logic: idempotency key, wallet debit atomicity, takeaway enforcement, promo validation)
- [x] **Auth** → stays Supabase (tokens already verified by `AuthenticateWithSupabase` middleware)
- [x] **Push** → stays with existing Expo push infra; Laravel triggers pushes through `PushNotificationService`
- [x] **Apps** → progressively re-point to `/v2/*` endpoints; store apps keep reading orders through their existing client until the migration lands

### Migration of checkout (do this first, it's a prerequisite)

Port the following from the `process-order` edge function into `OrderService`:

| Edge-function behavior | Laravel equivalent |
|---|---|
| `idempotency_key` → stable `payment_reference` (double-tap reuse) | Add `idempotency_key` to `StoreOrderRequest`; reuse existing order if reference exists |
| Server-side total calc (subtotal, service fee 5%, delivery fee, promo, takeaway) | Already in `OrderService::quoteCart` — extend with takeaway + delivery pricing table |
| Wallet debit + order creation in one transaction | `DB::transaction` in `createOrderFromCart` (already the pattern) |
| `payment_attempts` FAILED/PAID_ORDER_FAILED recording | Reuse existing `PaymentAttempt` model |
| Restaurant readiness checks (active, KYC approved, recipient code) | Add to `createCustomerOrder` guard |

---

## 4. Data Model (New Tables & Migrations)

Conventions: UUID PKs (repo uses `HasUuids`), `decimal(12,2)` for money, `timestamptz`, `order_snapshot`-style JSON snapshots for anything mutable, soft deletes where user-facing.

### 4.1 `riders` — one row per rider

```php
// 2026_XX_XX_000001_create_riders_table.php
Schema::create('riders', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
    $table->string('vehicle_type')->default('motorcycle'); // motorcycle | bicycle | car
    $table->string('plate_number')->nullable();
    $table->string('kyc_status')->default('not_started'); // not_started|submitted|under_review|approved|rejected
    $table->boolean('is_online')->default(false);
    $table->decimal('latitude', 10, 7)->nullable();   // latest-wins, single row per rider
    $table->decimal('longitude', 10, 7)->nullable();
    $table->timestamp('location_updated_at')->nullable();
    $table->decimal('rating', 3, 2)->default(0);
    $table->timestamps();
    $table->softDeletes();
    // Index for nearby queries (optional PostGIS upgrade path):
    // $table->index(['is_online', 'kyc_status']);
});
```

**Rules:**
- `latitude/longitude` are **latest-wins, one row per rider** — never a per-ping history table (cost).
- `is_online` gates dispatch eligibility.
- `kyc_status = approved` is required to receive dispatch offers.

### 4.2 `rider_documents` — KYC files

```php
Schema::create('rider_documents', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('rider_id')->constrained('riders')->cascadeOnDelete();
    $table->string('document_type');      // national_id | driver_license | guarantor | passport_photo
    $table->string('storage_key');        // R2/S3 key — never store file bytes in Postgres
    $table->string('mime');
    $table->unsignedInteger('size_bytes');
    $table->timestamps();
});
```

Use the existing media storage pattern (`MediaStorageService`, R2) for uploads. Reuse `RestaurantMediaController`-style signed upload endpoints.

### 4.3 `order_deliveries` — the trip (per order, 1:1)

```php
Schema::create('order_deliveries', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('order_id')->unique()->constrained('orders')->cascadeOnDelete();
    $table->foreignUuid('rider_id')->nullable()->constrained('riders')->nullOnDelete();
    $table->string('status')->default('pending_dispatch');
    // pending_dispatch → rider_assigned → rider_en_route_pickup → arrived_pickup
    //   → picked_up → en_route_dropoff → arrived_dropoff → delivered
    //   (+ failed | cancelled)
    $table->string('dispatch_mode')->default('manual'); // manual | auto
    $table->string('delivery_code', 4)->nullable();     // 4-digit PIN, generated at assignment
    $table->timestamp('assigned_at')->nullable();
    $table->timestamp('picked_up_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->jsonb('location_snapshot')->nullable();     // rider loc at completion (dispute evidence)
    $table->jsonb('metadata')->nullable();
    $table->timestamps();
});

// SAFETY CONSTRAINTS (copy these from dzpatch's dispatch gate — load-bearing):
// One active trip per rider:
$table->unique(['rider_id', 'status'], 'one_active_trip_per_rider')
    ->where(fn ($q) => $q->whereIn('status', [
        'rider_assigned', 'rider_en_route_pickup', 'arrived_pickup',
        'picked_up', 'en_route_dropoff', 'arrived_dropoff',
    ]));
// One rider per order is already covered by order_id UNIQUE above.
```

> Laravel note: partial unique indexes need raw SQL or `where` clauses on the Blueprint. If the driver (MySQL/Postgres) doesn't support partial indexes, enforce with a trigger or — better — a **serialized lock in the dispatch service** plus a unique index on `rider_id` where status is a single value isn't possible; use a generated column or the Postgres `CREATE UNIQUE INDEX … WHERE` raw statement. **Postgres recommended** (repo already uses `jsonb`).

### 4.4 Delivery pricing — upgrade to configurable zones

Current `delivery_pricing_settings` is a single global row (base_fee, per_km_fee, minimum_fee). Upgrade:

```php
Schema::create('delivery_pricing_settings', function (Blueprint $table) {
    // ADD columns:
    $table->decimal('free_distance_km', 6, 2)->default(4);
    $table->decimal('surge_multiplier', 5, 2)->default(1);
    $table->boolean('is_enabled')->default(true);
    // ADD nullable scope so restaurants can override the global default:
    $table->foreignUuid('restaurant_id')->nullable()->constrained('restaurants')->cascadeOnDelete();
});
```

**Pricing rule:** global row (restaurant_id = NULL) is the fallback; restaurant-specific row wins. **Snapshot the computed fee onto `orders.delivery_fee` at checkout** — never recompute against live settings after order placement (dzpatch rule: "Editing a pricing rule NEVER updates an existing order's snapshot").

Formula (mirror `process-order` exactly):
```
extra_km = max(0, distance_km - free_distance_km)
raw_fee  = base_fee + (extra_km * per_km_fee)
fee      = max(minimum_fee, raw_fee * surge_multiplier)
```

### 4.5 Rider earnings (money)

Reuse the existing wallet system rather than new tables:

- Rider's earnings credit their user `wallets` row.
- New `wallet_transactions.category` values: `delivery_earnings`, `commission`, `delivery_payout` (migration must extend the existing CHECK constraint: `('order_payment', 'refund', 'reversal', 'admin_credit')` → add the new ones).
- `delivery_earnings` row: `reference = "DELIVERY_EARNINGS_{delivery_id}"` with a **unique index on reference** → double-processing a completion is impossible (idempotent, mirrors `paystack_events`/ledger discipline from dzpatch).

Add a `commission_rate` to `delivery_pricing_settings` (platform % of the delivery fee) — rider earns `delivery_fee × (1 − commission_rate)`.

### 4.6 `order_status_history` — start actually writing to it

Already exists. Every transition (order or delivery) writes one row: `{order_id, status, changed_by_user_id, note}`.

---

## 5. Trip State Machine

### 5.1 Two statuses, one chain (keep existing apps working)

The existing store/customer apps read `orders.status`. Don't break them: keep the customer-facing chain on `orders.status` and run the rider-facing sub-machine on `order_deliveries.status`, **mapping** to the customer chain at two points:

```
orders.status:    pending → confirmed → preparing → ready → out_for_delivery → delivered
                                                               ▲                    ▲
order_deliveries.status:           rider_assigned → en_route_pickup → arrived_pickup
                                   → picked_up → en_route_dropoff → arrived_dropoff → delivered
```

**Mapping rules:**
- `order_deliveries.status` first enters `rider_assigned` → `orders.status = out_for_delivery`
- `order_deliveries.status = delivered` → `orders.status = delivered` (+ set `actual_delivery_time`)
- `order_deliveries.status = failed|cancelled` → `orders.status` stays `out_for_delivery` (ops re-dispatches) or moves to `cancelled` with a refund path (Phase 6+)

### 5.2 Transition legality table (single source of truth in `DeliveryService`)

| From | To (allowed) | Actor | Side effects |
|---|---|---|---|
| `pending_dispatch` | `rider_assigned` | restaurant / auto-dispatch / admin | generate `delivery_code`, push to rider, `orders.status = out_for_delivery` |
| `rider_assigned` | `rider_en_route_pickup` | rider | push to customer "Rider on the way" |
| `rider_en_route_pickup` | `arrived_pickup` | rider | push "Rider arrived at pickup" |
| `arrived_pickup` | `picked_up` | rider (store confirms optional) | set `picked_up_at`, push "Order picked up" |
| `picked_up` | `en_route_dropoff` | rider | push "On the way to you" |
| `en_route_dropoff` | `arrived_dropoff` | rider | push "Rider arrived" + delivery-code reminder |
| `arrived_dropoff` | `delivered` | rider (code verified) | set `delivered_at`, credit rider earnings, push receipt |
| any (pre-assign) | `cancelled` | customer/restaurant/admin | existing cancel flow |
| any (post-assign) | `failed` | admin | ops re-dispatch or refund (Phase 6) |

**Implementation:** `App\Services\DeliveryService::transition(OrderDelivery $delivery, DeliveryStatus $status, ?User $actor)` — validate the transition against a `legal_transitions` map, write `order_status_history`, fire push, run money side effects. **No raw `update(['status' => …])` outside the service.** Mirrors the existing `OrderService::transition` pattern.

### 5.3 Enums (Laravel)

```php
enum DeliveryStatus: string
{
    case PendingDispatch = 'pending_dispatch';
    case RiderAssigned = 'rider_assigned';
    case RiderEnRoutePickup = 'rider_en_route_pickup';
    case ArrivedPickup = 'arrived_pickup';
    case PickedUp = 'picked_up';
    case EnRouteDropoff = 'en_route_dropoff';
    case ArrivedDropoff = 'arrived_dropoff';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
```

Add `rider_assigned` to the `OrderStatus` enum? **No** — keep `orders.status` at `out_for_delivery` for the whole trip; the delivery detail carries the sub-state. This avoids touching the Supabase enum and all existing app code that reads order status.

---

## 6. API Contract (New Endpoints)

All under the `v2` prefix, `supabase.auth` middleware, matching existing route style.

### 6.1 Rider app endpoints

```
# Onboarding
POST   /v2/riders/apply                     # create rider profile + submit KYC payload
POST   /v2/riders/documents                 # upload document (signed URL via media service)
GET    /v2/riders/me                        # own profile + kyc_status
PATCH  /v2/riders/me                        # vehicle info, plate number
POST   /v2/riders/me/online                 # {is_online: bool} — gate dispatch eligibility

# Jobs
GET    /v2/riders/offers                    # dispatch offers for this rider (active + TTL)
POST   /v2/deliveries/{delivery}/accept     # accept offer (idempotent, single-winner)
POST   /v2/deliveries/{delivery}/decline    # decline offer

# Trip execution
POST   /v2/deliveries/{delivery}/en-route-pickup
POST   /v2/deliveries/{delivery}/arrive-pickup
POST   /v2/deliveries/{delivery}/pick-up
POST   /v2/deliveries/{delivery}/en-route-dropoff
POST   /v2/deliveries/{delivery}/arrive-dropoff
POST   /v2/deliveries/{delivery}/complete   # body: {delivery_code} — verified server-side

# Location + earnings
POST   /v2/riders/location                  # {lat, lng} — rate-capped (see §8)
GET    /v2/riders/earnings                  # earnings history
```

### 6.2 Customer endpoints

```
GET    /v2/orders/{order}/tracking          # {status, delivery: {delivery_status, rider: {name, phone, rating, location?, stale}}, history[]}
POST   /v2/orders/{order}/cancel            # exists — extend to handle assigned trips
```

### 6.3 Restaurant endpoints (extend existing)

```
GET    /v2/store/riders                     # riders belonging to this restaurant (self-dispatch list)
POST   /v2/store/orders/{order}/assign-rider   # body: {rider_id} — manual dispatch (v1)
POST   /v2/store/orders/{order}/request-dispatch # request auto-dispatch (v2)
```

### 6.4 Admin endpoints (foodhint-admin)

```
GET    /v2/admin/riders                     # list + filter by kyc_status / is_online
GET    /v2/admin/riders/{rider}             # profile + documents
POST   /v2/admin/riders/{rider}/approve     # {approved: bool, reason?}
POST   /v2/admin/deliveries/{delivery}/reassign   # {rider_id}
POST   /v2/admin/deliveries/{delivery}/fail      # {reason}
GET    /v2/admin/deliveries                 # active trip dashboard
PATCH  /v2/admin/delivery-pricing           # zone/restaurant pricing config
```

### 6.5 Response shapes

Follow existing `OrderResource` conventions. Tracking response example:

```json
{
  "success": true,
  "data": {
    "order_id": "…",
    "order_status": "out_for_delivery",
    "delivery_status": "en_route_dropoff",
    "estimated_delivery_time": "2026-08-11T13:15:00Z",
    "rider": {
      "name": "Chidi O.",
      "phone_number": "0803…",
      "rating": 4.8,
      "location": { "lat": 6.4512, "lng": 3.4218, "updated_at": "…", "stale": false }
    },
    "delivery_code_hint": "****"           // full code shown only in order details, never in tracking
  }
}
```

---

## 7. Dispatch Engine

### 7.1 Two modes, shipped in order

**Mode A — Store self-dispatch (v1, ships first):**
The store marks an order `ready` (existing flow), then picks one of their riders from `GET /v2/store/riders` → `POST /v2/store/orders/{order}/assign-rider`. This upgrades today's manual `courier` jsonb into a real rider entity with zero new dispatch complexity. Stores that already deliver with their own riders keep working exactly as they do now — just with a real rider record, tracking, and a delivery code.

**Mode B — Platform auto-dispatch (v2, the "Uber Eats" moment):**
Laravel queue worker (the `dispatch` job) runs when an order is marked `ready` and no rider was manually assigned:

1. Find eligible riders: `kyc_status = approved`, `is_online = true`, no active trip (constraint from §4.3), within radius (default 5 km, admin-configurable) of the restaurant.
2. Create an offer (insert row or in-memory job payload) with a **TTL (e.g. 60s)**.
3. Push the offer to eligible riders' devices (existing `PushNotificationService`).
4. First `POST /deliveries/{delivery}/accept` wins — enforced by the **one-active-trip-per-rider partial unique index** (the DB is the backstop, not app logic).
5. TTL expires with no accept → notify the restaurant to self-dispatch, or re-run the wave with a wider radius (configurable, default off).

**Idempotency rules (copy from dzpatch `_GATE_3.3_dispatch.md`):**
- `accept` is idempotent: same rider retrying returns the same success response (replay wins).
- A rider with an active trip is excluded at offer generation **and** blocked at accept.
- Admin `reassign`/`fail` are audited and idempotent.

### 7.2 No money in dispatch

Dispatch reads the order; it never touches wallets. All money happens at `delivered` (§9). This keeps the dispatch engine safe to retry freely.

---

## 8. Live Tracking Model

**Copy dzpatch v3's `TRIP_EVENT_MODEL.md` decisions verbatim in spirit** — it exists because dzpatch v2 burned real money streaming locations via Supabase Realtime + pg_net push, and v3 deleted both.

### 8.1 Rules (non-negotiable)

1. **Latest-wins, one row per rider.** `riders.latitude/longitude/location_updated_at` — no per-ping history table.
2. **Server rate-cap:** `POST /v2/riders/location` accepts at most 1 write per 5s per rider (respond `202` regardless so the client never retry-storms). The cap is the real cost control — client throttling is only an optimization.
3. **Movement-gating (client):** the rider app suppresses writes when the rider hasn't moved ~20m since the last successful write.
4. **Cadence:** ~10s while on an active trip; ~30s when stationary at pickup/dropoff; suppressed when idle/offline.
5. **Customer reads:** `GET /v2/orders/{order}/tracking` returns the rider's latest snapshot **only while** the delivery is in an active state (`rider_assigned` … `arrived_dropoff`). Before assignment and after `delivered`, `rider` is omitted.
6. **Stale flag:** if `location_updated_at` is older than ~60s, return `stale: true` + `updated_at` — never a frozen dot pretending to be live.
7. **Degrade to status-only:** if no location was ever written, the endpoint still returns the authoritative status. **Trip progress never depends on location.**
8. **Poll, don't push:** customer app polls every 10–15s while tracking. No websockets.
9. **Terminal stop:** all tracking I/O stops at `delivered`/`cancelled`/`failed`.

### 8.2 ETA

Replace the hardcoded `+45 minutes` at checkout with a simple model: `dispatch_buffer (15 min) + prep_time (restaurant) + travel_time(distance / avg_speed)`. Store the estimate on the order at creation (`estimated_delivery_time` already exists); recompute once when the trip actually starts.

---

## 9. Money Flow & Rider Payouts

### 9.1 Checkout (customer side — already live, keep)

Customer pays `subtotal + service_fee(5%) + delivery_fee + takeaway − discount` via wallet or card. `delivery_fee` is computed server-side from distance + pricing settings and **snapshotted** on the order.

### 9.2 On `delivered` (new — idempotent, transactional)

Inside the `complete` transaction:

1. Set `delivered_at`, `orders.actual_delivery_time`, write `order_status_history`.
2. **Credit rider earnings:** insert `wallet_transactions` row:
   - `user_id` = rider's user
   - `category = delivery_earnings`
   - `amount` = `delivery_fee × (1 − commission_rate)`
   - `reference = DELIVERY_EARNINGS_{delivery_id}` (unique index → replay-safe)
   - update wallet `balance` (or use the existing wallet service's credit method)
3. Record platform commission implicitly (fee − rider share). If a separate `commission` ledger category is desired, add it in the same transaction.

### 9.3 Withdrawals

Riders withdraw via the existing Paystack transfer pattern already used by stores (`paystack-create-recipient` edge function + payout flows). Reuse — don't build a new payout rail.

### 9.4 Cancellation / failure (Phase 6)

- Pre-assignment cancel: existing flow, unchanged.
- Post-assignment cancel/fail: ops decision → re-dispatch (preferred) or refund (existing refund/reversal ledger categories). No rider payment if not delivered.

---

## 10. Rider App (Expo)

A fourth Expo app in the foodhunts family (the repo already has customer + store-mobile + store-web). Use **foodhunt-store-mobile as the template** (auth via Supabase, media upload to R2, push, Expo Router structure).

### Screens (v1)

| Area | Screens |
|---|---|
| Auth | login, signup, forgot password (Supabase auth, same as store app) |
| Onboarding | apply (vehicle info), documents upload (NIN/ID/license), review state |
| Jobs | home feed (active offers with TTL countdown), offer detail |
| Trip | navigate to pickup (Google Maps deep link), confirm arrival, confirm pickup, navigate to dropoff, enter delivery code + optional POD photo, complete |
| Earnings | today/total earnings, transaction list |
| Profile | documents, vehicle, settings |

### Native requirements (permissions)

- Location: foreground (v1) — background location is a v2 option (dzpatch has `rider-background-location.ts` if you need the pattern)
- Push notifications
- Camera (POD photo, KYC documents) — reuse store app's upload flow

### OTA/publish safety

Follow the dzpatch lesson: if the rider app shares an Expo project with other variants, **set the variant env vars explicitly on every publish** (`EXPO_PUBLIC_APP_VARIANT`, `APP_VARIANT`) and verify `expo config --type public --json` before shipping. Safer: keep it a **separate project** (recommended — the foodhunts apps are already separate repos).

---

## 11. Admin & Store App Changes

### 11.1 foodhint-admin (Next.js)

New pages:
- **Riders** — list, filter by KYC status / online, detail view with documents
- **Rider approval** — approve/reject with reason; write `audit_logs`
- **Deliveries** — active trips board (order, rider, status, age), reassign, force-fail
- **Delivery pricing** — edit global/restaurant settings (base, per-km, min, surge, commission)

The admin currently reads Supabase directly. Since delivery lives in Laravel, these admin pages call the `/v2/admin/*` endpoints via the existing `middleware.ts`-protected fetch pattern (or move the whole admin to Laravel-served APIs progressively — out of scope here, but the new pages should hit Laravel, not Supabase).

### 11.2 foodhunt-store-mobile / store-web

- Replace the manual `courier` jsonb entry with: pick from **their riders** (`GET /v2/store/riders`) → assign → see live trip status on the order detail screen.
- Store settings page: manage their rider list (add rider by phone/email, deactivate).
- Keep the existing `ready` → "request dispatch" button as the Mode B trigger.

---

## 12. Customer App Changes

- **Order details:** show delivery status timeline (rider assigned → en route → picked up → arrived), rider name/phone, delivery code, live map card.
- **Tracking:** poll `GET /v2/orders/{order}/tracking` every 10–15s while status is `out_for_delivery`; render rider marker from the latest snapshot; handle `stale`.
- **Push:** existing push trigger covers status changes; add copy for new states ("Rider is on the way", "Order picked up", "Rider has arrived").
- **Checkout migration:** switch `useCheckout` from `supabase.functions.invoke("process-order")` to `POST /v2/orders` (after the OrderService port in §3). Keep the idempotency key behavior.

---

## 13. Phased Implementation Roadmap

### Phase 0 — Foundation (1–2 sprints)
- [ ] Decision signed: Laravel = source of truth for orders + delivery (this doc)
- [ ] Port checkout to Laravel `OrderService` (idempotency, takeaway, promo, payment_attempts recording)
- [ ] Customer + store apps re-point order creation/reading to `/v2/*`
- [ ] Add `delivery_earnings`/`commission` to `wallet_transactions` category CHECK
- **Exit:** orders flow end-to-end through Laravel with zero behavior change.

### Phase 1 — Rider entity + KYC (1 sprint)
- [ ] `riders`, `rider_documents` migrations + models
- [ ] Rider apply/documents/me endpoints
- [ ] Admin rider list + approval
- [ ] Rider app: auth, apply, documents upload, approval state
- **Exit:** riders can onboard and be approved; admin can manage them.

### Phase 2 — Manual dispatch + trip state machine (1–2 sprints)
- [ ] `order_deliveries` migration + safety constraints
- [ ] `DeliveryService` + transition legality + `order_status_history` writes
- [ ] Store: riders list + assign-rider; rider app: job detail + trip screens (en-route/arrive/pickup/complete)
- [ ] Delivery code generation + verification
- [ ] Customer: status timeline + push copy
- **Exit:** a store can assign one of its riders and the full trip completes with code verification. (This alone is a shippable "delivery" feature.)

### Phase 3 — Tracking + auto-dispatch (2 sprints)
- [ ] `POST /v2/riders/location` (rate-capped) + rider app location reporter
- [ ] `GET /v2/orders/{order}/tracking` + customer tracking screen with map
- [ ] Auto-dispatch worker (offers, TTL, single-winner, fallback)
- [ ] ETA model
- **Exit:** platform can dispatch to the nearest available rider; customers watch the rider live.

### Phase 4 — Money + admin ops (1–2 sprints)
- [ ] Rider earnings credit on `delivered` (idempotent)
- [ ] Rider earnings screen + withdrawals (existing Paystack rail)
- [ ] Admin: reassign, force-fail, delivery dashboard
- [ ] Delivery pricing admin UI (zones/restaurant overrides)
- **Exit:** riders get paid; ops can intervene.

### Phase 5 — Hardening (ongoing)
- [ ] Cancellation/refund matrix for assigned trips
- [ ] Dispute evidence (location snapshot at completion, POD photos)
- [ ] Rate limiting, audit coverage, load test the dispatch worker
- [ ] Background location for riders (optional)

---

## 14. What to Copy From dzpatch / What to Skip

### ✅ Copy

| Pattern | Source | Why |
|---|---|---|
| Trip state machine + event-driven push | `TRIP_EVENT_MODEL.md`, DATABASE_CONTRACT §11.2 | Single legal-transition table, clients never mutate status |
| One-active-trip-per-rider + single-winner DB constraints | `_GATE_3.3_dispatch.md` round 2 | The load-bearing guards against double-trip/double-accept |
| Replay-wins idempotency on accept | `_GATE_3.3_dispatch.md` | Retries never double-execute |
| Latest-wins rider location, poll-based tracking, server rate-cap, stale flag, status-only degradation | `TRIP_EVENT_MODEL.md` §1–8 | The entire cost-control playbook |
| Pricing snapshot on order; never live-recompute | DATABASE_CONTRACT C5 | Admin pricing edits can't mutate existing orders |
| No money in dispatch; money only on `delivered` | `_GATE_3.3` "Money posture: NONE" | Dispatch stays safe to retry |
| Ledger-style idempotent earnings (reference unique) | DATABASE_CONTRACT §7 | Double webhook / double-tap can't double-credit |

### ❌ Skip (package-delivery complexity, wrong for food)

- Bidding, counter-offers, negotiation timers (Wave 2) — food customers want speed, not auction
- Fleets / fleet managers — defer until rider supply justifies it
- DVA (dedicated virtual accounts), partner APIs, promo-code engine (CEO-deferred in dzpatch too)
- Service-area PostGIS polygons — a simple radius + lat/lng is enough for v1
- Supabase RLS/RPC/trigger architecture entirely — you're building on Laravel, and that's the point

---

## 0. Relationship to the master roadmap (Phases.pdf §18)

The master roadmap (`~/Downloads/Phases.pdf` — "FoodHunts Everything App — Execution Phases") is the **authoritative** plan for this work. This document is the working detail for its delivery slice:

| Master roadmap phase | This document |
|---|---|
| Phase 7.1 — delivery-provider module | §4.3, §5, §8 (trip state machine, tracking, reconciliation hooks) |
| Phase 7.2 — marketplace delivery E2E (fixed-price offers, delivery code, payout) | §7 (dispatch Mode B = fixed-price offer, no bidding), §9 (rider payout on delivered) |
| Phase 7.3 — package delivery standalone | §6 customer endpoints (mode A/B apply to packages too) |
| Phase 7.4 — rider hardening | §10 rider app + §8.1 rate-cap/stale rules |

**Roadmap rules this plan already follows:** one-merchant-per-order (single `restaurant_id` per order), immutable `order_snapshot` at checkout, no money moves at dispatch, idempotency on every money path, delivery code + proof at dropoff, reconciliation gates on delivered. **Roadmap additions to fold in later:** outbox/inbox worker for push + provider events (replace the current `PushNotificationService` direct calls), integer kobo ledger migration (Phase 3) for rider earnings, and the "complete launch set" (Phase 10.4) gating which verticals ship.

---

## 15. Risks, Gotchas & Open Questions

### 15.1 Risks

| Risk | Mitigation |
|---|---|
| **Dual-backend drift** — orders in Supabase, delivery in Laravel | Phase 0 moves checkout to Laravel before delivery work starts. Do not skip. |
| One-active-trip-per-rider violated under concurrency | Partial unique index is a DB constraint, not app logic (dzpatch CTO review, round 2) |
| Location cost explosion | §8 rules are contract, not client goodwill: rate-cap server-side, latest-wins, poll-based |
| Rider supply & KYC is an ops problem, not a code problem | Budget admin tooling + manual review time in Phase 1; dzpatch's hardest 20% was this |
| Double-payout on retried `complete` | `DELIVERY_EARNINGS_{delivery_id}` unique reference |
| Checkout regression during migration | Keep `process-order` live behind a feature flag until `/v2/orders` matches behavior exactly |
| Google Maps costs (rider navigation) | Rider app uses deep links to Google Maps for nav (free-ish); only the fee-distance calc + tracking need API keys |

### 15.2 Open questions to confirm before Phase 0

1. **Rider supply model:** platform-hired riders (Mode B) vs. restaurant self-dispatch (Mode A) vs. both? (Recommend: both, Mode A first.)
2. **Commission model:** platform % of delivery fee only, or also % of order value? (Recommend: % of delivery fee, e.g. 10–20%.)
3. **Who owns the rider when a restaurant self-dispatches:** restaurant-hired riders tied to one store, or platform riders any store can use?
4. **Delivery radius / zones:** city-wide single radius for v1, or per-restaurant coverage limits?
5. **Failed delivery policy:** re-dispatch automatically, or refund + cancel (with fee forfeited)?
6. **Cash on delivery:** v1 is prepaid-only (wallet/card) — confirm no cash orders in scope.

### 15.3 Non-negotiables (review gate)

- [ ] Every money-mutating endpoint is idempotent and writes `order_status_history`/`audit_logs`
- [ ] No direct `status` writes outside `OrderService`/`DeliveryService`
- [ ] Delivery pricing is snapshotted at checkout, never recomputed
- [ ] Rider location is latest-wins + rate-capped
- [ ] One active trip per rider is enforced by the DB

---

*Companion docs: `API_INVENTORY.md`, `supabase-migration-plan.md` (Foodhunts-backend/docs); dzpatch `TRIP_EVENT_MODEL.md`, `_GATE_3.3_dispatch.md`, `MONEY_STATE_MACHINES.md` (reference architecture).*
