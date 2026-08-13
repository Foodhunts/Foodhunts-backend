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
