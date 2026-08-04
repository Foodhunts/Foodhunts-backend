# Media storage foundation

The backend currently defaults to the legacy media provider. R2 is opt-in and
is enabled only with:

```env
MEDIA_STORAGE_DRIVER=r2
```

When R2 is enabled, Laravel requires these deployment-only variables:

```env
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=
R2_PUBLIC_URL=
R2_REGION=auto
R2_USE_PATH_STYLE_ENDPOINT=false
```

The R2 credentials must stay in Laravel Cloud or another trusted backend
environment. They must never be placed in mobile or browser `EXPO_PUBLIC_*`
variables or returned by an API.

## Categories and visibility

Restaurant logos, headers, covers, menu images, menu-item images, and public
promotion images are public categories. Restaurant KYC and owner national ID
documents are private categories. Callers cannot override this classification.

Public keys use deterministic namespaces with UUID filenames. Private keys use
the `private/restaurants/...` namespace. Original filenames are never used as
stored filenames, and SVG is not accepted.

## Foundation services

`MediaStorageService` is the only R2 storage boundary. It validates the opt-in
configuration, stores through the `r2` disk, verifies writes, reports size,
creates public URLs only for public categories, creates bounded temporary URLs
only for private keys, and accepts only application-generated keys for
deletion.

`MediaUrlResolver` preserves existing absolute HTTP(S) URLs and resolves public
R2 object keys without performing storage network checks. It never resolves a
private object key into a permanent public URL.

## Safe verification

Run the existing verification command only after configuring a non-production
R2 bucket and confirming the target environment:

```bash
php artisan foodhunts:test-r2
```

The command uses an isolated temporary key and deletes only that object. It was
not run as part of this batch. Upload endpoints, ownership authorization,
database integration, vendor migration, and the resumable backfill command are
later phases.
