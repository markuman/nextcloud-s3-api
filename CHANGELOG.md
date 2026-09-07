# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com).

## [Unreleased]

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
