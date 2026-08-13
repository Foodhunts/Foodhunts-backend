# Production Launch — Laravel Backend (Laravel Cloud)

**Status:** Runbook — staging (`foodhunts-backend-staging-ke3qu0.laravel.cloud`) is live and healthy.
**Target:** a production environment on Laravel Cloud serving real traffic, with the mobile apps pointed at it.

---

## 0. Preflight (verified)

- [x] Code on the deploy branch: `infrastructure/laravel-cloud` (latest commit `1a3ac01` — rider/delivery Phase 7 work).
- [x] `GET /api/health` returns `{"success":true,"service":"foodhunts-api","status":"healthy"}` on staging (HTTP 200).
- [x] Full migration set is reproducible (Blueprint `check()` fixes committed — roadmap Phase 0.4).
- [x] Runtime config: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=database`, `BROADCAST_CONNECTION=log`.

**Decision needed — production data:** start with a **fresh database** (recommended — real data still lives in Supabase; the Laravel DB fills as apps migrate to `/v2`). Do **not** copy the staging DB (it contains test/smoke rows).

---

## 1. Create the production environment (cloud.laravel.com)

1. Open [cloud.laravel.com](https://cloud.laravel.com) → the **Foodhunts-backend** project.
2. **Environments** → **Create environment** → type `production`.
3. **Deployment branch:** `infrastructure/laravel-cloud` (same branch staging uses — or `main` if you want a stricter promotion path; pick one and keep it).
4. **Database:** provision a new managed Postgres (do **not** reuse the staging DB). Note: Laravel Cloud generates a separate DB name/credentials for this environment.
5. **Domain:** default `foodhunts-backend-<slug>.laravel.cloud`; optionally attach a custom domain (e.g. `api.foodhunts.app`) via the dashboard once DNS is set.

## 2. Environment variables (production)

Set these in the production environment's **Variables** tab. Copy provider keys from staging, change environment-specific values:

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | the production domain (e.g. `https://foodhunts-backend-<slug>.laravel.cloud`) |
| `APP_KEY` | **generate fresh** — Laravel Cloud creates one; never reuse staging's |
| `APP_NAME` | `Foodhunts` |
| `APP_TIMEZONE` | `Africa/Lagos` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `warning` (production level, not debug) |
| `DB_*` | production DB values (Laravel Cloud fills these when the DB is attached) |
| `REDIS_*` | production Redis (Laravel Cloud managed) |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_STORE` | `redis` |
| `SESSION_DRIVER` | `database` |
| `BROADCAST_CONNECTION` | `log` |
| `SUPABASE_URL` / `SUPABASE_PUBLISHABLE_KEY` | same as staging (auth is Supabase-based) |
| `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` / `PAYSTACK_WEBHOOK_SECRET` | same live keys |
| `R2_*` | same as staging (media storage) |
| `EXPO_ACCESS_TOKEN` | same as staging (push) |
| `PUSH_PROVIDER` | `expo` |
| `FOODHUNTS_DEFAULT_DELIVERY_FEE` / `FOODHUNTS_SERVICE_FEE_RATE` | per product decision |
| `FRONTEND_URL` / `ADMIN_URL` / `STORE_URL` | production URLs of the apps |
| `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` | production domains |

> Rule: **APP_KEY and DB passwords are environment-unique.** Everything else can be copied from staging.

## 3. Deploy + migrate

1. Click **Deploy** in the production environment (or push to the configured branch — auto-deploy).
2. After the build succeeds, run migrations from the dashboard (**Deployments → Run migrations**) — or via CLI:
   ```bash
   laravel-cloud migrate
   ```
3. Enable the **queue worker** in the production environment settings (Laravel Cloud runs `php artisan queue:work` for redis queues; required once jobs/outbox land).
4. Verify:
   ```bash
   curl https://<production-domain>/api/health
   # {"success":true,"service":"foodhunts-api","status":"healthy"}
   ```

## 4. Point the apps at production

- **Mobile (foodhunt-mobile):** set `EXPO_PUBLIC_API_URL` to the production domain and `EXPO_PUBLIC_USE_LARAVEL_API=true` in the **production** build profile of `eas.json` (currently only the `test` profile sets these). Ship with the next store build.
- **Store web / store mobile / admin:** update their API base URL configs to the production domain when they start calling Laravel endpoints.

## 5. Post-launch checklist (roadmap Phase 1.1 / 9.3)

- [ ] Sentry (or error tracker) configured with `SENTRY_*` env vars — currently **absent** from `.env.example`.
- [ ] Backups enabled for the production database (Laravel Cloud managed backups).
- [ ] Webhook URL for Paystack updated to `https://<production-domain>/api/payments/paystack/webhook` (and the `v2` alias `/api/v2/webhooks/paystack`) — **test a real webhook after switching**.
- [ ] Rollback rehearsal: `php artisan migrate:rollback --step=1` on a staging copy before ever doing it on prod.
- [ ] Rate limits / abuse review on auth + payment routes.

---

## Rollback

- Laravel Cloud keeps previous deployments — **Deployments → previous build → Rollback**.
- If a migration must be reverted: `php artisan migrate:rollback --step=N` **after** verifying no prod rows depend on it.

## Open items (owner: you)

1. Custom domain (optional).
2. Production `APP_KEY` — generated by Laravel Cloud at environment creation.
3. Confirm branch policy: `infrastructure/laravel-cloud` vs `main` for production auto-deploy.
4. Sentry DSN + backups toggle.

## Launch log — 2026-08-13 (production switchover)

- Deploy 1 succeeded; **migrations failed**: `fe_sendauth: no password supplied`. Root cause: staging's `DB_PASSWORD` is injected at runtime by the platform (dashboard external-DB linkage); a CLI-created environment gets the `DB_*` vars but no password. Fix: copy staging's injected password into the env var list (`cloud environment:variables <env> --action=set --key DB_PASSWORD --value ...`) and **redeploy** (env vars bake in at deploy time; `command:run` won't see them until then).
- Shared Supabase DB already contained the v2 schema but the `migrations` ledger was empty → migrator tried to re-create `users` etc. Recovery:
  1. Marked existing migrations as applied in `migrations` (batch 1) by checking `information_schema.tables`.
  2. Two migrations were *partially* applied (`reconcile_v2_financial_and_tracking_tables`, `create_v2_supporting_tables`) → created the 10 missing tables manually, then ledgered both.
  3. ⚠️ **Pitfall**: Laravel's `getMigrationName()` strips `.php` — the ledger stores bare names (`2026_06_26_000001_create_users_table`, no extension). Ledger rows inserted with `.php` make `migrate:status` show everything `Pending`. Fix: `UPDATE migrations SET migration = replace(migration, '.php', '') WHERE migration LIKE '%.php';`
- After ledger fix: `php artisan migrate --force` applied exactly the 4 new rider/delivery migrations (`riders`, `rider_documents`, `order_deliveries`, `extend_wallet_transaction_categories`). `add_delivery_times_to_orders` was already satisfied (columns exist — added Supabase-side).
- `2026_08_05_000001_create_media_migration_manifests_table.php` is **uncommitted** (R2 media work, other dev) → not in the deployed commit; will apply on a future deploy. Don't run it manually.
- Verified live: `GET /api/health` → healthy; `GET /api/v2/restaurants` → real rows.
- Still open: Paystack webhook URL points at staging — update to production domain; mobile store build should set `EXPO_PUBLIC_API_URL` to the production domain.
