# Production R2 public-media migration

This process migrates only existing public restaurant (logo + header), menu, menu-item and app-ad image URLs from Supabase Storage to Cloudflare R2. It never migrates KYC or owner identity documents, and it never deletes Supabase or R2 objects.

## Before migration

1. Back up the production database, including `restaurants`, `menu_items`, and `media_migration_manifests` after its migration has been applied.
2. Confirm `MEDIA_STORAGE_DRIVER=r2` and all required R2 variables are configured in the production secret manager.
3. Confirm the R2 bucket and custom public URL are the intended production targets.
4. Confirm the deployment contains the manifest-table migration and run it through the normal Laravel deployment process.
5. Review the dry-run count and keep the command output as the migration record.

## Commands

Preview without changing records:

```bash
php artisan media:migrate-to-r2 --dry-run
```

Preview one restaurant:

```bash
php artisan media:migrate-to-r2 --dry-run --entity=restaurant --id=<restaurant_uuid>
```

Execute only after reviewing the preview:

```bash
php artisan media:migrate-to-r2 --limit=100 --batch-size=25 --confirm
```

Resume failed or interrupted entries:

```bash
php artisan media:migrate-to-r2 --resume --batch-size=25 --confirm
```

Verify completed manifests and their R2 objects:

```bash
php artisan media:migrate-to-r2 --verify-only
```

## Rollback

Rollback restores the previous Supabase URLs recorded in completed manifests. It does not delete R2 objects.

```bash
php artisan media:rollback-r2-migration
php artisan media:rollback-r2-migration --confirm
```

If a URL was changed after migration, the rollback reports a conflict and leaves that record unchanged.

## Post-migration checks

- Review failed and skipped manifest rows.
- Verify completed R2 keys, sizes, MIME types, checksums, and public URLs.
- Confirm restaurant and menu-item APIs return the new URLs.
- Confirm existing Supabase URLs for records outside the migration scope still render.
- Confirm no Supabase object was deleted.
- Keep the manifest and database backup until the migration is accepted.

## Execution log — 2026-08-13 (production)

- Dry run: 1370 candidates (restaurants 121, menus 223, menu_items 984, app_ads 10).
- Ran `media:migrate-to-r2 --limit=250/500/750/1000/1370 --batch-size=25 --confirm` sequentially (idempotent; completed entries skipped on later runs). ~3-4 min per 250.
- Result: **1333 completed / 37 failed**; R2 bucket now holds 1333 objects (1318 under `restaurants/`, 10 under `promotions/` for ads); sampled URLs return 200 with correct content types; Laravel `/api/v2/restaurants` returns R2 URLs.
- Failure analysis: 32 Cloudinary URLs (pre-Supabase provider, still functional — left untouched), 5 Supabase edge cases left in place:
  - 1 SVG logo (test account), 2 HEIC files, 1 oversized (>5MB public limit), 1 source already deleted (HTTP 400 — dead link).
- Rescue pass: 5 mislabeled files (jpeg bytes with .png/.webp names) migrated via direct storage path (bypasses strict downloader, same verified upload).
- Rollback available via `media:rollback-r2-migration --confirm` (restores Supabase URLs from manifest).
- NOT migrated (by design): KYC/owner identity docs (43), historical JSON snapshots (orders/transactions/buy-for-me metadata), Supabase objects themselves (nothing deleted).
- Follow-ups: switch store upload drivers to `laravel` (MEDIA_UPLOAD_DRIVER / EXPO_PUBLIC_MEDIA_UPLOAD_DRIVER) in production; migrate admin ads uploads to a Laravel endpoint; consider Supabase object deletion + quota relief after soak.
