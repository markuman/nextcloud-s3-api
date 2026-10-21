# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com).

## 1.6.0 – 2026-10-21

### Security
- **XML request bodies switched entity substitution on instead of off.** `DeleteObjects` and
  `CompleteMultipartUpload` parsed their body with `LIBXML_NOENT`, which — despite the name, and
  despite the comment next to it — makes libxml *substitute* entities, and load external ones
  (`file://`, `php://filter`) to do so. `DeleteObjects` echoes every key back in its response, so
  outside of Nextcloud that combination reads any file the web server can read, `config.php`
  included, for anyone holding a readwrite key. Inside Nextcloud it was not exploitable: core
  installs an external entity loader that returns nothing, and the app's safety rested entirely on
  that default. Internal entities were expanded, though — a key could be spelled as `&k;`. The flag
  is gone, and a body that declares a DTD at all is refused with `MalformedXML`: no S3 request
  carries one, and without a DTD there is nothing to expand, whatever the defaults. The check runs
  on the parsed document, so a UTF-16 body cannot hide its `<!DOCTYPE` from it.

### Fixed
- **A key with an empty segment addressed a different object.** Nextcloud collapses runs of slashes
  in the request URI before routing, so a request for `a//b` — or `/b`, as in `bucket//b` — reached
  the app as `a/b` and was served from there; a `DELETE` removed `a/b`. The key is now taken from
  the raw request URI, where the empty segment is still visible, and refused with `InvalidArgument`
  like any other key that cannot be a path. Slashes between the endpoint and the bucket name are
  still tolerated, since a client configured with a trailing slash on the endpoint sends
  `…/s3//bucket/key`.
- The source key of `CopyObject` and `UploadPartCopy` gets the same validation as a key in the
  request line. Before, only a `..` anywhere in it was refused: `a//b` and `a/./b` copied from
  `a/b`, a source inside `.s3-uploads/` read the parts of an upload in progress, and a valid key
  such as `a..b` could not be copied at all.
- **Multipart uploads filled the trash bin.** Completing or aborting an upload deleted its scratch
  folder with an ordinary delete, which Nextcloud's trash bin intercepts: every upload left a full
  copy of its parts in the owner's trash, plus an empty `.s3-uploads` folder each time a bucket's
  last upload finished — twice the space until the trash expired. A part uploaded twice also left
  the first copy behind as a file version, which deleting the folder did not remove. Scratch data
  is now deleted past the trash bin (the app answers the trash bin's `MoveToTrashEvent` for exactly
  that folder), and a re-uploaded part replaces the old one instead of overwriting it, so no
  version is created. Objects deleted through `DeleteObject` still go to the trash, as before.
  What earlier releases left behind is not cleaned up: trash entries named after upload ids or
  `.s3-uploads` expire with the trash, and stray versions remain under
  `files_versions/<bucket folder>/.s3-uploads/`, where nothing reads them.

### Added
- **An integration test suite** (`tests/`). `tests/run.sh` starts a throwaway Nextcloud in a
  container, enables the app and runs pytest against the real endpoint: SigV4 and presigned URLs,
  conditional writes under concurrency, listings and pagination, ranged reads, multipart and
  `UploadPartCopy`, `aws-chunked` bodies with tampered chunk signatures, read-only keys, and the
  settings API. It also drives [gits3](https://github.com/markuman/gits3srv) — its own S3 client
  and a real push / clone / gc cycle through it — when `gits3.py` is found next to this repository.
  The cleanup job and the object locks are covered by ageing rows in the test instance's database:
  abandoned uploads, stale and held locks, orphaned ETags. Not part of the release tarball.
- `NC_VERSION=master tests/run.sh` runs the suite against Nextcloud's daily master build — the
  next major before it has an image — by swapping the daily tarball into the newest stable image.

### Changed
- **Nextcloud 36 is in the supported range** ahead of its release: `max-version` moves from 35 to
  36. A major upgrade disables every app whose `max-version` is below the new server, so declaring
  it only after 36 ships would switch the app off for everyone upgrading on day one. No code
  changes were needed. Checked against the daily master build (36.0.0 dev): all OCP classes this
  app uses still exist and none is deprecated, no deprecated method is called, `info.xml` validates
  against master's schema (unchanged since `stable35`), the code lints clean under PHP 8.5 with
  `E_ALL`, the full test suite passes, and the server logs no deprecation for the app while it
  runs.
- The walgit example config no longer sets `max_retries`: walgit's S3 backend never reads it and
  retries through the AWS SDK's default of three attempts. The README now says what that means for
  the `503 SlowDown` that racing writes into a new folder can get.

## 1.5.4 – 2026-10-20

### Fixed
- **Deleting a key that names a folder moved the whole folder to the trash.** `DeleteObject` and
  the bulk `DeleteObjects` resolved the key and called `delete()` on whatever node came back,
  without checking that it was a file. A `DELETE` on `repos` — while `repos/a`, `repos/b`, … exist
  — therefore removed the entire tree. Keys with a trailing slash were already rejected, but the
  bare prefix was not. In S3, `repos` does not exist just because `repos/a` does, so such a delete
  is now a no-op that answers `204` (or lists the key as deleted in a bulk request), matching what
  a missing key does. Reads already refused folders this way.

## 1.5.3 – 2026-09-07

### Fixed
- **A conditional write took its lock on the wrong identity, so compare-and-swap could lose an
  update.** The lock key hashed the bucket id, but one file can be reached through two buckets:
  nothing stopped registering `/Documents` as `foo` and `/Documents/sub` as `bar`, after which
  `foo/sub/x.txt` and `bar/x.txt` are the same file with two independent locks. Two concurrent
  `If-None-Match: *` requests, one per bucket, would then both observe "absent" and both succeed —
  exactly the silent lost update that content-derived ETags were introduced in 1.2.0 to prevent.
  The lock is now keyed on the object's resolved storage path, which is what actually identifies
  the file. Note that hashing the bucket's *folder* would not have been enough either: in the
  example above the two buckets have different folders, and it is the full path of the object that
  has to agree.
- Registering a folder that another bucket already serves is refused, including the nesting case
  above. Two names for one folder have no use that a single bucket with a key prefix does not cover
  better, and they leave the app treating one directory as two buckets — separate multipart scratch
  areas inside it, two ETag rows racing over one file id. Existing overlapping buckets are left
  alone: they still work, and now share a lock correctly. The comparison normalizes the path first,
  because `/Documents//sub` and `/Documents/./sub` both pass the folder-exists check — Nextcloud
  collapses those itself — and would otherwise have slipped past the overlap check while naming a
  folder that is already served. (`/Documents/other/../sub` does not get that far: Nextcloud
  refuses a path containing `..`, so the folder check already answers `400`.)
- Creating a bucket whose name is already taken answers `409` with a readable message. It used to
  hit the unique index and surface as a `500` carrying the raw driver error, for what is the most
  ordinary mistake in that form. The index is still what decides — two racing requests can both
  pass the pre-check — and that collision now maps to the same `409` rather than a `500`.
- Unexpected failures while creating a bucket no longer return the exception message to the
  browser; it could carry a filesystem path or driver detail. They are logged instead.

### Changed
- **PHP 8.2 is supported again**, down from the 8.3 that 1.5.2 briefly required. `<php
  min-version>` is resolved against the server, so it has to be satisfiable on the oldest
  Nextcloud in the range — and 32 (which asks for 8.1) through 34 (8.2) all run on 8.2. Nextcloud
  35 does need 8.3, but it enforces that itself, so the lower floor costs its users nothing while
  32–34 keep working on the PHP their Nextcloud actually asks for. Verified by linting on 8.1, 8.2
  and 8.3; nothing in the app uses a language feature past 8.1, and 8.1 stays out only because it
  is end of life.

## 1.5.2 – 2026-09-07

### Changed
- Nextcloud 35 is supported: `max-version` moves from 34 to 35. No code changes were needed —
  every OCP interface this app uses was checked against `stable35` and all 37 of them still exist.
  `TimedJob` is the one worth naming: it dropped the deprecated `execute()` in 33, but this app
  implements `run()`, which is still the abstract method. Access control was already declared with
  `#[NoAdminRequired]`, `#[NoCSRFRequired]` and `#[PublicPage]` attributes rather than the
  docblock annotations Nextcloud logs a deprecation for.
- **The supported range narrows to Nextcloud 32–35 and PHP 8.3+**, from 30–34 and PHP 8.1+.
  Nextcloud 35 requires PHP 8.3, and the app store resolves `<php min-version>` against the server
  rather than against Nextcloud's own floor, so a single declared minimum has to cover the whole
  range. Choosing 8.3 means users on 32, 33 or 34 need a PHP newer than their Nextcloud asks for;
  that is the deliberate cost of testing against one interpreter instead of four, and PHP 8.1 is
  out of security support regardless. Nothing in the app needs a language feature past 8.1 — this
  bounds what is tested, not what parses. Nextcloud 30 and 31 are end of life and go with it.

## 1.5.1 – 2026-09-05

### Changed
- First release published to the Nextcloud app store. No code changes: the app metadata gained the
  `bugs` element the store requires, an SPDX licence identifier in place of the deprecated `agpl`
  shorthand, and the element order the XSD prescribes — `info.xml` did not validate before. The
  store also reads `CHANGELOG.md` and `LICENSE` from inside the archive, and neither existed at the
  `v1.5.0` tag. Cutting a version rather than moving that tag leaves what `v1.5.0` points at
  unchanged for anyone who already fetched it.

## 1.5.0 – 2026-08-26

### Fixed
- A `Date` header is converted to ISO8601 before signing, so clients that send it instead of
  `X-Amz-Date` no longer fail signature verification.

### Security
- Streaming upload bodies are bound to the credential. With
  `STREAMING-AWS4-HMAC-SHA256-PAYLOAD` the SigV4 signature covers only the headers, so the
  per-chunk signature chain is now verified — without it the uploaded bytes were not tied to the
  key that signed the request.
- Key listings are scoped to the requesting user.

## 1.4.0 – 2026-08-26

### Added
- `InvalidPartOrder` is reported for a misordered part list on CompleteMultipartUpload.

### Fixed
- Unsatisfiable ranges answer `416` correctly; unusable keys are rejected with a client error
  rather than a 500.

## 1.3.0 – 2026-08-25

### Added
- A background job reclaims abandoned multipart uploads, stale locks and orphaned ETags after a
  week. Requires Nextcloud's cron to be running.

### Fixed
- Listings for a prefix that contains no slash.

## 1.2.0 – 2026-08-25

### Added
- `CopyObject` and `UploadPartCopy`, enabling server-side compose within a bucket.

### Changed
- ETags are derived from object content instead of Nextcloud's internal etag. The latter is
  `md5(mtime + inode + dev + size)`, and since mtime has one-second resolution, two writes of
  equally sized but different content within the same second shared an etag — enough for a
  compare-and-swap to accept a lost update.
- Conditional writes are atomic: concurrent writers to one key are serialised, so exactly one of a
  set of racing creates or updates succeeds.

## 1.1.0 – 2026-08-25

### Added
- Multipart uploads.
- Presigned URLs, ranged reads and conditional reads.
- `aws-chunked` request bodies are decoded rather than stored verbatim.
- Conditional writes via `If-Match` / `If-None-Match`.

### Changed
- Unknown subresources (`?acl`, `?tagging`, …) answer `NotImplemented` instead of being ignored.
  An ignored subresource would otherwise be treated as an operation on the object itself.
- Unhandled errors return XML, as S3 clients expect.

### Fixed
- Prefix listings.

## 1.0.0 – 2026-08-25

### Added
- Initial release: Nextcloud folders exposed as S3 buckets, per-bucket API keys with
  readonly/readwrite permission, AWS Signature V4 authentication, and the core object and bucket
  operations.

### Fixed
- Settings page crash on Nextcloud 32+.
