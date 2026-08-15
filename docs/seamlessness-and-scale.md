# Foodhunts — Seamlessness & Scale Playbook

**Status:** Draft for team review
**Audience:** Backend (Laravel), Mobile (Expo), Admin (Next.js), and Product/Operations
**Scope:** How to make the ordering → booking → accepting → tracking → delivery flow feel instant and reliable, on Nigerian networks, and how to scale it without rewriting it.
**Companion docs:** `delivery-feature-implementation.md` (the *what* — tables, endpoints, state machine, phases). This document is the *how and why* — the architectural principles behind a seamless, scalable experience.

---

## 1. Executive summary

"Seamless" is not one feature. It is the product of five layers that must be built together:

1. **A single source of truth** — every order/trip is a strict state machine; both sides render the same event log.
2. **Atomic, idempotent transitions** — accept/complete/pay can never double-fire, even under retry on a flaky network.
3. **Latency hiding** — optimistic UI, upfront pricing, and client-side interpolation so the user never *sees* the network.
4. **The right transport per use-case** — push for critical events, short polling for location, never a fragile always-on websocket as the only path.
5. **Cost discipline** — on Nigerian data plans, every byte and every location ping costs the user money; seamlessness must not bankrupt the rider or the platform.

The single most important rule: **if the DB says state X, every screen shows state X.** Everything else is elaboration.

---

## 2. Nigeria reality — the constraints that shape every decision

These are not edge cases; they are the default operating environment.

| Constraint | Real-world shape | Design consequence |
|---|---|---|
| **Unreliable mobile networks** | 2G/3G/4G mix, tower handoffs, dead zones, drops mid-call, high latency | Assume every request fails at least once. Idempotency + retry + resumable state are mandatory, not nice-to-have. |
| **Expensive data** | Pay-per-MB; users are data-conscious | Minimize payloads, cache aggressively, never re-send full menus/lists repeatedly, compress. |
| **No structured addressing** | No reliable postal code; addresses are landmarks + descriptions | GPS pin is the primary address; free-text + landmark + phone number are co-equal. Rider *calls* on arrival — this is the norm. |
| **Payments** | Paystack (card, bank transfer, USSD); card declines and 3DS friction are common | Wallet-first + Paystack; idempotent webhooks (`paystack_events`); v1 prepaid-only (already decided). |
| **Low-end rider devices** | Entry-level Android, limited battery/RAM/storage | Small APK, foreground location only (v1), movement-gated GPS, battery-friendly. |
| **Power outages** | Both rider and customer can go dark mid-trip | State lives in the DB and is resumable; the delivery code must verify even after a reconnect. |
| **Trust & fraud** | Ghost deliveries, double payouts, fake riders | KYC (NIN/license/guarantor), 4-digit delivery code, proof-of-delivery photo, rider ratings. |

**Implication:** in Nigeria, "seamless" means **offline-tolerant and retry-safe**, not merely "low-latency." A rider who loses signal for two minutes must be able to resume the trip and complete it without support intervention.

---

## 3. The seamless flow, end to end

Trace the exact arc a customer and rider experience, and what makes each hop feel instant.

### 3.1 Order (the request)

- Show the **upfront delivery fee + ETA before the user commits** (distance-based fee already exists in `process-order`; replace the hardcoded `+45 min` ETA with `dispatch_buffer + prep_time + travel_time` — see §3.2 of `delivery-feature-implementation.md`).
- **Snapshot pricing at checkout** — never recompute against live settings after placement. (Already a non-negotiable.)
- Return the order ack **immediately** (optimistic), then reconcile. The user sees "Order placed" while the server finishes wallet debit + idempotency check.

### 3.2 Book (dispatch)

- Do the heavy work **asynchronously**. The request moves to `MATCHING`; a queue worker finds an eligible rider. The user never waits on a synchronous "find a rider" call.
- Eligible rider = `kyc_status = approved` + `is_online = true` + **no active trip** + within radius. Query by a simple `lat/lng` bounding box + index for v1 (add H3/PostGIS only when the radius query is measurably slow).
- Send a **trip offer with a TTL** (e.g. 60s) via push. Fan out to a shortlist, not every rider.

### 3.3 Accept (the handoff)

- The accept must be an **atomic single-winner claim in the database**, not a check-then-write in app code:

```sql
-- One rider wins; everyone else's UPDATE affects 0 rows.
UPDATE order_deliveries
   SET rider_id = :rider, status = 'rider_assigned', assigned_at = now()
 WHERE id = :delivery
   AND rider_id IS NULL
   AND status = 'pending_dispatch';
```

- The partial unique index `one_active_trip_per_rider` (from `delivery-feature-implementation.md` §4.3) is the **backstop** — even if two requests race, the DB rejects the second. This is the #1 defense against "two riders accepted the same order."
- **Idempotent accept:** the same rider retrying returns the same success response (replay-wins), so a dropped packet on a bad connection doesn't produce an error or a double-assignment.

### 3.4 Track (the shared state machine)

- From the moment of accept, **both apps render the same event log.** Every transition (`rider_assigned → en_route_pickup → arrived_pickup → picked_up → en_route_dropoff → arrived_dropoff → delivered`) writes `order_status_history` and pushes a notification. Neither app mutates status directly; only `DeliveryService`/`OrderService` do.
- The customer sees the rider's **interpolated** position between polls (client-side smoothing), not raw ping jumps.

### 3.5 Complete (the money)

- Money moves **only on `delivered`**, inside one transaction: set `delivered_at` → credit rider earnings (idempotent via `reference = DELIVERY_EARNINGS_{delivery_id}`) → push receipt. Never at dispatch. (Already specified in §9 of the implementation plan.)

---

## 4. Realtime strategy on flaky networks

**Decision: push for events, short-poll for location, no always-on websocket as the only path.**

| Use case | Transport | Why |
|---|---|---|
| Status change / offer / accept / complete | **Expo push** (already live) | Survives app backgrounding and brief offline; the OS delivers when reconnected. |
| Customer tracking (rider location) | **Short-poll every 10–15s** (already chosen) | Polling is *more* robust than websockets on tower handoffs — a dropped poll is just a retry, not a reconnect storm. |
| Rider job feed / offers | Push + a **pull fallback** (`GET /v2/riders/offers`) | A rider who missed the push can always pull their current offers with TTL. |
| Supabase Realtime | Use **sparingly** (order status, chat if added later) | Realtime has concurrent-connection limits and reconnects poorly on 2G/3G. Do not make it the backbone. |

**Rules that keep it cheap and reliable (from the dzpatch `TRIP_EVENT_MODEL.md` lessons):**

- Rider location is **latest-wins, one row per rider** — never a per-ping history table.
- Server **rate-caps** `POST /v2/riders/location` (1 write / 5s / rider, return `202` regardless).
- Client **movement-gates** writes (~20m moved) and varies cadence (10s active, 30s stationary, off when idle).
- Tracking always returns a **`stale` flag** when `location_updated_at` is old — never a frozen dot pretending to be live.
- **Degrade to status-only** — trip progress never depends on location data.

---

## 5. The reliability backbone (this is what "never breaks" means)

1. **Idempotency keys on every money/state-mutating call.** Reuse the `payment_reference` / `DELIVERY_EARNINGS_*` / `paystack_events` discipline already in place. A retried accept, complete, or webhook is a no-op, not a double-charge or double-payout.
2. **Single writer per state.** No raw `update(['status' => …])` outside `OrderService`/`DeliveryService`. (Already a review gate.)
3. **Atomic compare-and-swap for claims** (accept, assign) — the DB is the source of truth, app logic is only the polite path.
4. **Outbox/inbox for push + provider events.** Write the notification intent in the same transaction as the state change; a worker drains the outbox and sends the push. If the push fails, the row is still there to retry. (Listed as a future roadmap addition — promote it: this is the difference between "usually sends" and "never silently drops.")
5. **Resumable state.** A rider who goes offline mid-trip re-fetches their trip and continues; the state machine is idempotent, so re-submitting the same transition is harmless.

---

## 6. Client-side UX patterns (the "feel" layer)

These are the cheap moves that make the app *feel* instant:

- **Optimistic UI:** show "Order placed" immediately; reconcile with the server in the background. Never block the confirmation toast on a slow network round-trip.
- **Upfront everything:** locked fee + ETA before commit; no surprises at the end.
- **Interpolated movement:** animate the rider dot between location snapshots (linear/spline), so 10–15s polls look like continuous motion.
- **One-tap call rider:** the Nigerian delivery norm is a phone call on arrival. Make it one tap from the tracking screen.
- **Offline-tolerant rider flow:** confirm-pickup / confirm-dropoff / enter-code actions queue locally if the network is down and sync when it returns — the server accepts the transition because the delivery code is verified server-side, not client-side.
- **Graceful degradation:** if location is unavailable, show status timeline + rider name/phone and keep going. Tracking is an enhancement, not a dependency.

---

## 7. Scalability architecture (phased, no rewrite)

Scale is a *budget* you grow into, not a big-bang. The shared Supabase Postgres + Laravel-on-Laravel-Cloud + R2 + Paystack stack already has the right bones; the scaling levers are added as the numbers demand.

### 7.1 v1 — today (correctness first)

- Single region, shared Supabase Postgres (pooler), Laravel Cloud (stateless), R2 for media, Paystack for money.
- Poll-based tracking, rate-capped location, push for events.
- **Handle ~1k orders/day** with these alone, *provided* idempotency + atomicity are in place.

### 7.2 Scale to ~10k orders/day — trigger when:

- Tracking poll QPS starts to hurt the DB, or
- Dispatch fan-out / webhook processing adds latency to the request path, or
- Discovery (`restaurants_nearby`) becomes the hot query.

**Actions:**
- **Move work off the request path** into Laravel queue workers: dispatch offers, push outbox, webhook handlers, report generation.
- **Cache discovery** (Redis): cache `restaurants_nearby` / menu reads with short TTL + invalidation on change. Home screen is the highest-QPS read; don't hit Postgres for it every time.
- **Add indexes** on hot paths: `orders(user_id, created_at)`, `order_deliveries(rider_id, status)`, `riders(is_online, kyc_status)`, `addresses(user_id)`.
- **Connection pooling** is already present (Supabase pooler) — keep app connections bounded, never one-per-request.
- **CDN** for media (R2 + cache). Images should never round-trip to origin on every view.

### 7.3 Scale to ~100k orders/day — trigger when:

- Write throughput on `orders`/`order_deliveries`/`wallet_transactions` saturates a single writer, or
- Read QPS exceeds what caching + replicas can absorb.

**Actions:**
- **Read replicas** for tracking/discovery/history reads; writes stay on the primary.
- **Partition hot tables by time** (`orders`, `order_status_history`, `wallet_transactions`) — archive old partitions.
- **Stateless horizontal Laravel** (multiple app instances behind a load balancer; optionally Octane); scale queue workers independently of web.
- **Rate limiting at the edge** (the platform firewall already exists on Laravel Cloud) — protect `/v2/riders/location` and money endpoints aggressively.
- **Move realtime to a dedicated, connection-aware service** only if chat/live-fleet is added; otherwise polling + push continue to scale fine.
- **Dedicated metrics/SLOs** (below) before this stage — you can't scale what you can't measure.

**Principle throughout:** add the scaling lever *when a metric says so*, not proactively. Premature partitioning and replica topology are a maintenance tax that pays nothing at v1 scale.

---

## 8. Cost control — the Nigeria dimension

Seamlessness must not cost more than it earns. The three cost centers and their controls:

| Cost center | Control |
|---|---|
| **Location data** (rider GPS) | Rate-cap server-side, movement-gate client-side, latest-wins storage, poll not stream. (Already specified — treat as contract, not goodwill.) |
| **Push + data payloads** | Push only on *meaningful* transitions; minimize JSON; cache; ETags for list reads. |
| **Maps/navigation** | Rider navigation via deep links to Google Maps (free-ish); only fee-distance calc + tracking rendering need API keys. (Already flagged.) |

---

## 9. Monitoring & SLOs

Instrument before you need it. Minimum viable observability:

- **Latency:** P95 for checkout POST, accept, tracking poll, push delivery.
- **Errors:** error rate + 5xx split by endpoint (Sentry/Logtail or Laravel Cloud logs).
- **Business:** order→delivered conversion, dispatch time-to-accept, % orders with a rider assigned within N minutes, delivery-code failure rate.
- **Realtime health:** tracking poll QPS, rider location write rate, stale-location ratio.

**Target SLOs (v1):**

| Signal | Target |
|---|---|
| Order ack (optimistic) | < 200ms perceived |
| Accept → both apps show "matched" | < 1s |
| Dispatch offer fan-out | async, < 1s |
| Tracking poll P95 | < 300ms |
| Push delivery | < 5s |
| Duplicate/double-money events | **0** (enforced, not measured) |

---

## 10. Non-negotiables (review gate)

- [ ] Every money-mutating endpoint is **idempotent** and writes `order_status_history`/`audit_logs`.
- [ ] No direct `status` writes outside `OrderService`/`DeliveryService`.
- [ ] Accept/assign is an **atomic compare-and-swap**; one-active-trip-per-rider is a **DB constraint**.
- [ ] Delivery pricing is **snapshotted** at checkout, never recomputed.
- [ ] Rider location is **latest-wins + rate-capped + stale-flagged**.
- [ ] Critical events ship through an **outbox**, not a direct synchronous push.
- [ ] Tracking **degrades to status-only**; trip progress never depends on location.

---

## 11. Decision log & open questions

| # | Question | Recommendation |
|---|---|---|
| 1 | Push vs. Realtime vs. poll for tracking | Poll (chosen) — keep it; add push only for events. |
| 2 | Outbox pattern for push | **Adopt now** — it's the cheapest fix for "push silently dropped" on flaky networks. |
| 3 | Offline rider actions (queue + sync) | **Yes for v1** — the delivery-code model already makes this safe. |
| 4 | Cash-on-delivery | v1 prepaid-only (confirmed). Revisit only if a market demands it. |
| 5 | Read replicas / partitioning timing | Defer to §7.2/§7.3 triggers; do not pre-build. |
| 6 | H3/PostGIS for dispatch radius | Defer; bounding-box + index is enough until measured otherwise. |
| 7 | Supabase Realtime scope | Only for order-status/chat niceties; never the tracking or dispatch backbone. |

---

*This document complements `delivery-feature-implementation.md` (feature build-out) and inherits the dzpatch lessons referenced there (`TRIP_EVENT_MODEL.md`, `_GATE_3.3_dispatch.md`, `MONEY_STATE_MACHINES.md`). Where the two disagree, the implementation plan's data model and state machine win; this document governs transport, UX, reliability, and scaling policy.*
