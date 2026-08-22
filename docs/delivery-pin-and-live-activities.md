# Delivery PIN + iOS Live Activities — Feature Plan

**Status:** For review
**Owner:** Backend (Laravel) + Customer app (Expo `foodhunt-mobile`)
**Reference:** Chowdeck UX (screenshots) + `docs/delivery-feature-implementation.md`

---

## Overview

Two Chowdeck-parity features for the FoodHunts customer experience:

1. **Delivery PIN** — a 4-digit code the customer shares with the rider to confirm handoff ("Meet your rider and say `7453`").
2. **iOS Live Activities** — a lock-screen / Dynamic-Island live view showing live order progress ("Your order will be ready in 25 minutes") with the system "Allow Live Activities" prompt.

---

## Feature 1 — Delivery PIN

### Current state (verified in code)

| Piece | Status |
|---|---|
| `delivery_code` (4-digit, 1000–9999) generated at assignment | ✅ `DeliveryService::assignRider()` → `generateDeliveryCode()` |
| `delivery_code` column on `order_deliveries` | ✅ |
| Server-side verification on `complete` | ✅ `DeliveryController::transition()` — `hash_equals($delivery->delivery_code, $code)`, 422 on mismatch |
| Ride-state machine (`arrived_dropoff → delivered`) | ✅ `DeliveryService::transition()` |
| **Customer sees the code** | ❌ `OrderController::tracking()` returns a stub (`history: []`) |
| **Customer app displays the code** | ❌ no UI |
| **Rider app (enters the code)** | ❌ rider app not built (separate `foodhunt-rider` repo, plan §10) |

**Conclusion:** the backend trust boundary is done. The remaining work is *exposing* the already-existing code to the customer and *rendering* it.

### Remaining work

1. **Backend — `OrderController::tracking()`**: return the active delivery's status, the `delivery_code`, and a rider summary. Follow the plan §6.5 shape, but reveal the full code (not a hint) in the tracking payload — the customer is the one who must *read it aloud* to the rider.
   - Code is shown only while the delivery is in an active state (`rider_assigned` … `arrived_dropoff`); omitted before assignment and after `delivered`/`cancelled`/`failed`.
2. **Customer app — `app/orders/details/index.tsx`**: add a "Delivery PIN" card when the order is `out_for_delivery`. Show the 4-digit code large, with the "share with your rider" microcopy and the step flow (rider arrives → share PIN → rider confirms → enjoy).
   - Data source: extend `useRealtimeOrder` / the tracking fetch to include `delivery_code` + `delivery_status`.
3. **Rider app** (future, separate repo): `complete` screen already has a backend contract — `POST /v2/deliveries/{delivery}/complete` with `{ delivery_code }`. No backend change needed when the rider app ships.

### Verification

- Backend: unit test that `tracking` returns the code in active states and omits it before assignment / after `delivered`.
- App: manual — place order → assign rider → confirm the PIN card renders with the same 4 digits the rider sees.

---

## Feature 2 — iOS Live Activities

### What it is

A persistent, glanceable status view on the lock screen and Dynamic Island that updates as the order progresses — no app-in-foreground required. The Chowdeck screenshot shows:

- **"Your order will be ready in 25 minutes"** — a countdown + status line.
- The **"Allow Live Activities from FoodHunts?"** system prompt (iOS auto-prompts on first `Activity.request()`).

### Technical reality (Expo SDK 54)

Live Activities are **not** covered by `expo-notifications` (that's push only). They require **ActivityKit** (iOS 16.1+), which means native Swift code plus a config plugin. There is no first-class Expo module as of SDK 54, so this is a custom **local Expo module** (Swift + `ActivityAttributes` + `Activity` + `ActivityContent`).

### Design

1. **Native module** `expo-live-activity` (local):
   - Swift `OrderActivityAttributes` (`ContentState`: status label, restaurant name, countdown `estimatedReadyAt`, isRiderAssigned, pin hint).
   - `start(order)`, `update(state)`, `end()`.
   - Config plugin sets `NSSupportsLiveActivities` (and `NSSupportsLiveActivitiesFrequentUpdates` for minute-level countdown ticks) in `Info.plist`.
2. **App wiring** (`foodhunt-mobile`):
   - On order placed/confirmed → `start()` with the first status ("Your order will be ready in ~X minutes").
   - On each realtime status change (`useRealtimeOrder`) → `update()`.
   - On `delivered`/`cancelled` → `end()`.
   - Request permission the first time an order is active (triggers the system prompt).
3. **Countdown**: `ActivityContent` with a `.timer` / `Text(timerInterval:)` — the Dynamic Island shows a live countdown. ETA source: the order's `estimated_delivery_time` (currently hardcoded `+45 min`; see delivery plan §8.2 for the real ETA model — **do the ETA upgrade in the same phase**, otherwise the countdown is fake).

### Constraints / caveats

- **iOS only.** Android has no equivalent; skip silently on Android.
- **Requires a native rebuild** (`expo run:ios` → prebuild → pods → xcodebuild). Not deliverable via OTA.
- **Apple entitlements**: app-started Live Activities need no special provisioning; *push-updated* Live Activities need an APS entitlement. v1 should use **app-started** updates driven by the customer's own app polling the tracking endpoint (no push entitlement), matching the delivery plan's "poll, don't push" rule.
- **Simulator support**: Live Activities run in the simulator (iOS 16.1+), so validation is possible without a device.

---

## Phased roadmap

| Phase | Scope | Deliverable |
|---|---|---|
| **1 (this pass)** | Delivery PIN: backend `tracking` exposure + customer app UI | Backend PR + app OTA |
| **2** | Real ETA model (replace hardcoded `+45 min`) | Backend PR |
| **3** | Live Activities native module + app wiring + rebuild | Native module + store build |
| **4** | Rider app `complete` flow (code entry) | `foodhunt-rider` repo |

---

## Open questions

1. **Live Activities** require a native rebuild and (for push-updates) Apple entitlements — confirm app-started-only is acceptable for v1.
2. **Rider app** is a separate repo not yet created — the PIN feature's rider-side entry lands there. Do you want me to scaffold `foodhunt-rider` now, or keep this pass scoped to backend + customer app?
3. **ETA source** for the countdown — upgrade the hardcoded `+45 min` now (Phase 2) or defer?
