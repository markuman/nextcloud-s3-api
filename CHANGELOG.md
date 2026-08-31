# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com).

## [Unreleased]

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
