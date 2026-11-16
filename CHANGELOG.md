# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com).

## 1.7.3 – 2026-11-16

**Bugfix and hardening release** after a review of 1.7.2: one high, five medium and eight low
findings. Eleven are fixed; three only in part, as their entries say — listing a very large flat
folder, the first hash of a large file, and external changes on local storage that keep size and
mtime. A MariaDB-only `500` under contention turned up on the way. One migration, which adds a
column (`s3api_etags.nc_etag`) and rewrites nothing; recorded ETags, compound multipart ETags
included, stay as they are. Operator-visible: Nextcloud's naming rules now apply to S3 writes,
uploads cut short are refused, and app passwords without file access can no longer manage
buckets and keys.

### Security
- **Keys ending in `.part` got around the quota and were invisible.** `Folder::newFile()` does not
  check names — WebDAV calls `verifyPath()` itself before it gets there, this app did not. Nextcloud
  takes a name ending in `.part` for an upload in progress: the quota wrapper does not limit what is
  written to it, and the file cache never records it. So `x.part`, or anything below `d.part/`, sat
  on the disk outside the quota and outside every listing, the web UI's included — up to 5 GiB per
  `PUT`, as often as a readwrite key liked. Every segment of a key is now checked with Nextcloud's
  `IFilenameValidator` before `PutObject`, `CopyObject` and the multipart calls write anything, and
  `.part` is refused on its own as well, whatever the configuration. **Operator-visible:** the
  admin's naming rules — forbidden names, characters, extensions, the Windows compatibility mode —
  apply to S3 now; they did not before. Refused names answer `400 InvalidArgument`, `.htaccess`
  included (was `403 AccessDenied`). So do key segments of 251 to 255 bytes: the validator stops
  at 250, the file cache's limit for a name, where the app's own check allowed 255. Reads and
  deletes still reach a file stored under such a name some other way.

  **Finding objects stored this way by 1.7.2 or earlier:** they are in no listing, not in the web
  UI and not in the quota, so only the data directory shows them. As the web server user:

  ```bash
  find /path/to/data -path '*/files/*' -name '*.part' ! -name '*.ocTransferId*.part'
  ```

  This lists files and folders whose name ends in `.part` below users' `files/` (and group folders
  under `__groupfolders/` are found too if you drop `-path`); the excluded pattern is WebDAV's own
  temporary upload files, which are fine to leave. Everything below a listed folder is affected as
  well. These objects could be read with `GET`, so they may hold real data — a `*.iso.part` from a
  downloads folder, say. To keep one, rename it on the disk to a name without `.part` and let
  Nextcloud pick it up: `occ files:scan --path="/<user>/files/<folder>"`. What you do not want,
  delete with `rm`; nothing in Nextcloud's database refers to it. With object storage as primary
  storage there is no data directory to search.
- **An app password without file access could issue itself S3 keys.** Such a token gets an empty
  filesystem from Nextcloud, but creating a key does not touch the filesystem, and the key then
  reaches the files without any token — so without the restriction. The settings API answers `403`
  to such tokens on every endpoint. (`#[PasswordConfirmationRequired]` was considered and left out:
  the settings page would need the confirmation dialog, and the documented HTTP API is used with
  app passwords from automation.)

### Fixed
- **Truncated or wrong bytes stored with `200`.**
  - A plain request body was not held to its `Content-Length`. Whether PHP hands on a body that was
    cut short depends on the SAPI; without a checksum — presigned and `UNSIGNED-PAYLOAD` uploads
    usually have none — the first part of it replaced the object. Now `400 IncompleteBody`, as on
    S3, and nothing is stored.
  - Short writes to the temp directory (a full disk) were taken for complete ones: `fwrite()`
    reports them as a smaller count, not as `false`. Every copy now writes all of its bytes or
    fails.
  - `UploadPartCopy` with a range did not check its seek: on a storage that cannot seek, the part
    was copied from offset 0. It skips forward by reading now, as ranged `GET` always did, and a
    source that ends early fails the request.
  - `CompleteMultipartUpload` stopped at a failed read of a part and stored what it had — and then
    deleted the parts. A failed read is an error now, and the assembled size is checked against the
    sum of the parts before anything is stored.
  - Every write checks the size Nextcloud stored against what it was given, and answers `500`
    instead of `200` when they differ; a part of the wrong size is removed again. The size is read
    back from the file, so an unconditional write racing another one to the same key can trip
    this too; that costs the client a retry, and the warning in the log names both causes.
- **Conditional headers were ignored where S3 honours them.** `If-Match` / `If-None-Match` on
  `CompleteMultipartUpload` and `CopyObject`, and `x-amz-copy-source-if-match`, `-if-none-match`,
  `-if-modified-since`, `-if-unmodified-since` on `CopyObject` and `UploadPartCopy`: a
  compare-and-swap client got `200` for an update it had lost. They are evaluated now — the target
  conditions under the object lock, as on `PutObject` — and answer `412 PreconditionFailed`. A
  multipart upload whose condition fails stays as it was and can be completed or aborted, as on S3;
  `CompleteMultipartUpload` checks once before assembling the object as well, so a `412` does not
  cost a copy of it first.
- **A large write under the object lock could have its lock broken.** The lock counts as stale after
  fifteen minutes, sized for one body of at most 5 GiB. A conditional `CompleteMultipartUpload` or
  `CopyObject` now stores its object while holding the lock, and that object can be any size. A
  write that knows its size extends the lock by the time it takes at 4 MiB/s, at most by six hours
  — about 43 minutes for 10 GiB. That much longer, after a crash, conditional writes to the key
  answer `503`.
- **Parallel first parts of a multipart upload could fail with `403`.** SDKs send the first parts
  at once, and each of them created the upload's folder; Nextcloud waits 25 ms for the winner of
  that race and then refuses the losers — a `403` no client retries. The folder is now made by
  `CreateMultipartUpload`, and the shared `.s3-uploads` root under the app's lock, the same fix as
  #3 for parent folders. A part still creates a missing folder — uploads started before 1.7.3 have
  none yet — but only under that lock and while the upload exists: a part racing an abort used to
  leave a folder behind that nothing would ever remove. One case remains, logged with its path: an
  upload from before 1.7.3 aborted while its very first part is being written.
- **The temp directory was not bounded everywhere.** A ranged `UploadPartCopy` wrote its range
  there unchecked, and completion checked the bucket's free space but not the temp directory's —
  a 100 GB object needs 100 GB there. Both check now, and so does every upload body;
  `UploadPartCopy` also takes at most 5 GiB per part and the bucket's free space, like `UploadPart`.
  The README now says that PHP keeps a second copy of every request body outside of
  `tempdirectory`, which on a tmpfs `/tmp` is RAM.
- **Conditional writes could fail with `500` on MariaDB / MySQL.** InnoDB answers several requests
  waiting on one key of the lock table's unique index with a deadlock, not with a duplicate key,
  and that was not taken as "the lock is held". It is now, for taking, breaking and releasing a
  lock, and for the ETag table. Found by the new database matrix in CI; SQLite and PostgreSQL never
  raise it.
- **A share or mount named `.s3-uploads` in the bucket was used for parts.** On a bucket that is
  the home folder, another user could share a folder of that name: parts went into it, to its
  owner, and pruning would have deleted it. A scratch folder that is not on the bucket's own
  storage is refused (`403`) and left alone.
- **Deleting a bucket left its unfinished uploads behind**, and with the bucket record gone the
  cleanup job could no longer find their parts. They go with the bucket now, upload by upload:
  `.s3-uploads` belongs to the folder, and another user's bucket on the same folder (a share, a
  group folder) keeps its uploads. A part that cannot be removed — being written, on a read-only
  share — is logged, and the bucket goes anyway. Parts left by a bucket deleted before 1.7.3 stay
  in its folder's `.s3-uploads`, where the owner can delete them.
- **A recorded ETag was only checked against size and mtime.** A change that kept both — a client
  that sets the mtime, a write within the same second — kept the old ETag, and `If-Match` matched a
  version that was gone. Nextcloud's etag is the third criterion now. That closes it where
  Nextcloud's etag is unique per write (object storage as primary, most external storages); on
  local storage it is itself derived from mtime, inode, device and size, and an in-place rewrite
  within the same second still goes unnoticed — see the README. Rows from before are trusted once
  more and completed on their next read, so nothing is hashed again on upgrade.
- **The first `HEAD` or `GET` of a large file written outside the API hashes all of it before
  answering**, and client retries each started a hash of their own. One request hashes a file at a
  time now; the others wait briefly and then get `503 SlowDown`. A hasher that dies blocks the file
  for a minute, after which another request takes over. Still open: a file large enough to take
  longer to hash than a client keeps retrying fails that client, as it did before. Files written
  through the API are never hashed on read.
- **Listings walked the whole tree again for every page.** A continuation skips folders the earlier
  pages went through, and entries outside the prefix or before the marker are dropped before
  sorting. Common prefixes that sort before the continuation point are no longer repeated. A single
  folder with very many entries is still read in full for each page; Nextcloud's file API offers
  no way to read a slice of it.
- **A `PUT` body sent as JSON or form data could replace the key.** The routed path was a request
  parameter, and asking for one makes Nextcloud decode such a body; a top-level `path` in it took
  the route's place — `400 InvalidURI`, or a `500` for one that was not a string. `curl
  --data-binary` sends form data by default, rclone JSON for `.json` files. The handlers take no
  parameters now; the path comes from the request URI alone, checked against the one Nextcloud
  routed by. **Not fixed, and not the app's to fix:** Nextcloud still reads such a body into memory
  whenever any code asks for a parameter — the Text app does on every file write — and a JSON body
  past `memory_limit` kills the request, through WebDAV just the same. See the README.
- **A completed multipart upload could be reported as failed.** Removing its parts ran after the
  object was stored, and a failure there answered `503`; the client's retry with `If-Match` then
  met the new ETag and got `412`. Cleaning up is best effort now, and the cleanup job takes what is
  left.
- An `aws-chunked` chunk size of more than 16 hex digits converted to an undefined value — 0 among
  them, which reads as the final chunk. Not exploitable, the signature covers the data; refused
  before converting now.
- The query string was parsed twice, once for the signature and once for the handlers. One parser
  now, so the two cannot drift apart.

### Development
- CI runs the suite on MariaDB and PostgreSQL as well (`DB=mariadb|postgres tests/run.sh`), for the
  oldest and newest stable Nextcloud, and PHPStan against `nextcloud/ocp` (`tests/phpstan/`). The
  `sql` test fixture goes through Nextcloud's own database connection, so it works on all three.

## 1.7.2 – 2026-11-13

**Bugfix release.** No migration.

### Fixed
- **Concurrent uploads contended on shared parent folders.** Several `PutObject` requests whose
  keys share a parent that does not exist yet all tried to create it, and the losers ran into
  Nextcloud's file locking — `503 SlowDown` that clients had to retry, under load sometimes past
  their retry budget. Creating a missing parent folder now takes the app's lock for that folder
  and checks again once it holds it, so one request creates it and the others reuse it; uploads
  themselves stay concurrent. The warning "Tried to create an object store folder that already
  exists" in the log came from the same race and was harmless. (#3, thanks @sidohaakma)
  The `404` responses reported in #2 do not come from this race and are still being looked into.

## 1.7.1 – 2026-10-26

**Hardening release** after a regression review of 1.7.0: one medium and four low findings, all
addressed. No migration. Operator-visible: uploads with a checksum that does not match are
refused, and buckets from before 1.6.2 that sit on an incoming share are no longer bound by path.

### Security
- **Upload checksums were not checked.** `PutObject` and `UploadPart` only compared a literal
  `x-amz-content-sha256` with the body; `Content-MD5` and `x-amz-checksum-*` — as header or as
  `aws-chunked` trailer — were ignored. With `UNSIGNED-PAYLOAD` a signed checksum is all that ties
  the body to the request, so a body swapped in flight (behind a TLS-terminating proxy), or
  corrupted on the way, was stored without complaint. Every claim is now checked against the
  decoded bytes, hashed while the body is read, and a mismatch is `BadDigest`, as on S3; nothing
  is stored. A trailer checksum not announced in `x-amz-trailer` is `InvalidRequest`; one that is
  announced — `x-amz-trailer` is signed — but never arrives is `IncompleteBody`, as on S3, so
  stripping the trailer does not switch the check off. The signature line of a signed trailer is
  passed over: the checksum is checked against the bytes, and those are bound by the chunk
  signatures already. Current
  SDKs (boto3 ≥ 1.36 and others) send these checksums by default; the CRC-64/NVME one costs
  noticeably more CPU than the others, since PHP has no native implementation.
- **`+xml` types were served inline.** RSS, GPX, KML and every other `…+xml` object opened from a
  presigned URL rendered as an XML document on the Nextcloud origin — an XHTML root makes that a
  page with working forms. They are served as downloads now, like HTML, SVG and plain XML.
- **SigV4a streaming bodies were taken as unsigned.** `STREAMING-AWS4-ECDSA-P256-SHA256-PAYLOAD`
  bodies were decoded, but their chunk signatures never checked. They are `NotImplemented` now,
  and any `x-amz-content-sha256` that is neither a hash nor a supported sentinel is
  `InvalidArgument`, as on S3.
- **A wrong chunk signature was not throttled.** Only failures of the request signature counted
  towards brute force protection; a chunk signature fails later, while the body is read. Both
  count now, and both are logged as a warning.
- **A bucket from before 1.6.2 could still be bound to a foreign share.** The rule was: a share
  older than the bucket is taken. But a group share from before the bucket only appears at its
  path when the user joins the group — possibly after the bucket's folder was renamed away.
  **Operator-visible:** an incoming share is no longer bound by path at all; such a bucket answers
  `NoSuchBucket`, logs why, and its owner registers the folder again. Buckets already bound, by
  the 1.6.2 migration or since, are not affected.

### Documentation
- **The per-user HTTP API behind the settings page is documented** (README, *Managing buckets and
  keys programmatically*): `/apps/s3_api/api/v1/buckets` and `…/buckets/{id}/keys`, with Basic
  auth as the user (an app password) and `OCS-APIRequest: true`. Users and their automation can
  create buckets and keys without the web UI; keys still belong to their owners. Nothing about the
  API changed, it just was not written down.
- The README links the [`markuman.nextcloud`](https://github.com/markuman/markuman.nextcloud)
  Ansible collection, whose `s3_bucket` and `s3_bucket_info` modules and `s3_buckets` lookup
  (35.0.0 and later) manage buckets and keys through that API.

## 1.7.0 – 2026-10-25

### Added
- **A folder picker in the personal settings.** The folder path field has a *Choose…* button next
  to it that opens Nextcloud's file picker, restricted to folders: navigate into a folder and
  choose it. The home folder itself is not taken from the picker — a bucket on the whole home
  blocks every other one — so that one has to be typed as `/`. Typing a path by hand still works,
  and a path that does not exist is refused as before.
- **`occ s3api:bucket:create`** pre-provisions bucket configs, for one or more users or for every
  member of a group, so an admin can roll the app out without each user clicking through the
  settings:
  ```bash
  occ s3api:bucket:create --user alice --folder /S3 --name files --create-folder
  occ s3api:bucket:create --group staff --folder /S3 --name files --create-folder
  ```
  `--create-folder` creates the folder in each user's home when it is missing; without it a
  missing folder is an error. No API keys are created — the users create their own on the bucket
  that then shows up in their settings.
  - Re-running is safe: a user whose bucket of that name already serves that folder is skipped.
    Any other clash — the name on a different folder (also after the user renamed the folder),
    or a folder inside or around another bucket — is an error for that user, and nothing is
    created for them, not even the folder.
  - An unknown user or group aborts the run before anything is created for anyone, and so does
    `--folder /`: a bucket on the whole home blocks every other bucket of that user, which for a
    group is a typo rather than a plan. Users who want it register it in their settings.
  - An incoming share at the path, or above it, is refused: whoever shares a folder named like
    the one picked for a group would otherwise receive everything its members upload.
    `--allow-shared` accepts it when that is the point.
  - A group covers its members at the time the command runs, so users who join later need
    another run. The exit code is non-zero when any user could not be provisioned.

  Bucket configs created this way are logged like the ones from the settings page.

## 1.6.2 – 2026-10-24

**Hardening release.** No new features: this works through a security review of 1.6.1 — no
critical or high findings, a set of medium and low ones, all addressed. Updating is recommended.
It carries one migration, which binds existing buckets to their folder's file id (see the first
entry). Operator-visible: buckets on shares newer than the bucket stay unbound,
`DeleteObjects` needs a signed checksum or payload hash, keys that are not NFC are refused, and
uploads are bounded by 5 GiB and the owner's free space.

### Security
- **A bucket was its folder's path, checked once at creation.** Renamed, the folder stopped being
  served; whatever later appeared under the old name was served instead — a new folder, or a
  folder another user shared, which Nextcloud mounts under its own name. A key handed to a third
  party then read and wrote the other user's files. Buckets are now bound to the folder's file id
  and follow it through renames and moves; a folder in the trash answers `NoSuchBucket`. The
  migration binds existing buckets to what their path names now — except an incoming share that
  is newer than the bucket, since that is what the attack looks like once it has happened.
  **Operator-visible:** such a bucket, one on a federated share, and one whose folder cannot be
  found stay unbound with a warning in the log; binding is retried on first use by the same rule,
  otherwise the owner registers the folder again. Buckets on the owner's folders, group folders,
  external storage and on shares older than the bucket bind as before.
- **The XML bodies of `DeleteObjects` and `CompleteMultipartUpload` were not bound to the
  signature.** They were read raw, without checking `x-amz-content-sha256`, so a captured request
  could be replayed within its fifteen minutes with another body: other keys deleted, another part
  list assembled. They are now read like uploads (decoded, chunk signatures checked) and checked
  against every claim the request makes about them — the payload hash, `Content-MD5`, and for
  `DeleteObjects` `x-amz-checksum-*` (CRC32, CRC32C, CRC64NVME, SHA-1, SHA-256). `DeleteObjects`
  also has to carry one the signature covers, as S3 requires a checksum there; a request with
  `UNSIGNED-PAYLOAD` and nothing else is refused with `InvalidRequest`. A checksum sent as an
  `aws-chunked` trailer (`STREAMING-UNSIGNED-PAYLOAD-TRAILER`) does not count, as trailers are not
  read; it takes a signed header or a literal payload hash.
- **Keys in another Unicode normalisation, or not UTF-8 at all, were accepted.** Nextcloud stores
  names in NFC, so the NFD spelling of `é` was a second name for the same file: with a lock of its
  own, so two `If-None-Match: *` writes could both win; past the self-copy guard, which with file
  locking off truncated the object; past the bucket overlap check. Bytes that are not UTF-8 ended in
  a database error. Such keys, and keys with control characters, are refused with
  `InvalidArgument`. The overlap check compares folders by where they are now, NFC. Self-copy is
  detected by file id, which also covers case-insensitive storages.
- **Request bodies had no size limit of the app's own.** A body went to the temp directory in full
  before the quota was looked at, so one upload could fill it for the whole instance; XML bodies
  were held in memory and parsed whatever their size. `PutObject` and `UploadPart` now take at most
  5 GiB, as on S3, and no more than the bucket owner's free space (`403 AccessDenied`) — checked on
  the declared length before reading, and while reading. XML bodies stop at 4 MiB
  (`MaxMessageLengthExceeded`), `CompleteMultipartUpload` at 10000 parts. Completion checks the
  free space before assembling.
- **Listings hashed every file without a recorded ETag, in full.** A readonly key could make one
  `GET` read a thousand large files. A page now hashes at most 256 MiB; past that, files are listed
  with Nextcloud's etag plus `-0` until their content ETag is known — the suffix keeps tools from
  taking it for an MD5, which on some storages it looks like. A conditional write computes a missing
  ETag before taking the object lock, not while holding it.
- **`ListParts` re-read every part on each call**, and `CompleteMultipartUpload` read them three
  times. Part ETags are recorded when the part is stored.
- `ListParts` and `ListMultipartUploads` need a readwrite key.
- The object lock is keyed on where the file really is (storage and path in it), not on the
  owner's view of it, so a writer coming through a share and one through the owner's own bucket
  now exclude each other.
- A request URI that does not account for the routed path is refused (`InvalidURI`) instead of
  falling back to the routed path, which is the one that may have lost a slash.
- XML responses replace what XML cannot carry instead of producing an unparseable document or an
  empty value.
- Secrets are marked `#[\SensitiveParameter]`, so a logged stack trace does not carry them. The
  settings page clears a new secret from the page when its dialog closes.

### Changed
- **Operator-visible:** a contended conditional write waits up to three seconds for the object
  lock, not ten, with backoff, before answering `503 SlowDown`.
- Failed signatures and unknown keys are logged as warnings with the key and address, and the
  brute force entry carries the key. Bucket and key changes and `DeleteObjects` are logged at info
  level and to `admin_audit`.
- `ListParts` and `ListMultipartUploads` page (`max-parts`/`part-number-marker`,
  `max-uploads`/`key-marker`/`upload-id-marker`, `prefix`). At most 1000 uploads per bucket may be
  in flight; a bucket at that limit discards its expired uploads itself when the next one starts.
  Cleanup reclaims abandoned uploads 200 per run.
- The settings API answers `400` instead of `500` to parameters of the wrong type and to labels
  over 255 characters.
- Files whose names no key can spell are left out of listings.
- README: `trusted_proxies` behind a reverse proxy, room for `tempdirectory`.

## 1.6.1 – 2026-10-23

### Fixed
- **A listing prefix was resolved as a path without being checked like a key.** Its folder part
  went straight to Nextcloud, which refuses `..` (that surfaced as `403`) but folds `./`, `//` and
  `\` into another folder: `./.s3-uploads/` listed the multipart scratch area, which is only hidden
  at the bucket root, and `a/./` listed `a/` under keys that do not exist. Such a prefix — and
  `.s3-uploads/` itself — now matches nothing, as no key can contain those segments.
- **A lock held for more than two minutes could be broken by a waiting write.** The write under the
  lock stores the whole body, which on slow storage takes longer than that for a big object; the
  next conditional write broke the lock and both went ahead, losing an update. A lock is now
  stale after fifteen minutes. Releasing deletes the request's own row rather than whatever row the
  key has, so a request whose lock was broken no longer lets a third one in on finishing.
  **Operator-visible:** if a PHP process dies while holding a lock, conditional writes to that one
  key now answer `503 SlowDown` for up to fifteen minutes instead of two. Unconditional writes and
  reads are not affected. To clear it sooner, delete the key's row from `oc_s3api_locks`.
- Folder names in a listing prefix that Nextcloud refuses to look up (an admin-forbidden name, a
  character the storage cannot hold) give an empty listing instead of a `500`.

### Changed
- The key rules (segments, length, backslash, NUL, scratch area) live in one place, `KeyRules`,
  used both for keys and for listing prefixes.

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
- **Keys of a disabled account kept working.** Nextcloud serves a disabled user's folder to
  whoever asks for it, so the S3 endpoint stayed open after the login was closed. A key now
  authenticates only while its account is enabled. When an account is deleted, its buckets, keys
  and open uploads are deleted with it — before, a user created later under the same id would have
  inherited them, working keys included.
- **`host` and every `x-amz-*` header must be signed**, as on AWS. Unsigned, anyone able to alter a
  request in flight could have added `x-amz-copy-source` to a captured PUT and turned it into a
  copy of another object. Refused with `AccessDenied`.
- An unknown bucket name and another bucket of the same account now get the same `404
  NoSuchBucket`. A key could tell them apart before (`403` for the latter) and so list its owner's
  other buckets, which `ListBuckets` deliberately does not show.
- Wrong secrets and unknown access keys count towards Nextcloud's brute force protection (action
  `s3_api_auth`), so an address that keeps guessing is slowed down. A stale clock does not count.
- Objects of a type a browser renders as a document — HTML, SVG, XML, JavaScript — are served with
  `Content-Disposition: attachment`, and every object with `X-Content-Type-Options: nosniff`. A
  presigned URL opens on the Nextcloud origin, where an HTML object could otherwise pose as a login
  page.
- Request bodies, assembled multipart uploads and copied ranges are buffered in Nextcloud's
  `tempdirectory` instead of `php://temp`, which spills to the system temp directory. The file is
  unlinked as soon as it is open, so nothing is left behind even if PHP dies mid-request.

### Fixed
- **A key with an empty segment addressed a different object.** Nextcloud collapses runs of slashes
  in the request URI before routing, so a request for `a//b` — or `/b`, as in `bucket//b` — reached
  the app as `a/b` and was served from there; a `DELETE` removed `a/b`. The key is now taken from
  the raw request URI, where the empty segment is still visible, and refused with `InvalidArgument`
  like any other key that cannot be a path. Slashes between the endpoint and the bucket name are
  still tolerated, since a client configured with a trailing slash on the endpoint sends
  `…/s3//bucket/key`.
- A backslash in a key is refused: Nextcloud reads it as a path separator, so `a\b` was a second
  name for `a/b`, with a lock of its own.
- Keys longer than 1024 bytes answer `KeyTooLongError`, a path segment over 255 bytes
  `InvalidArgument`, and a name Nextcloud refuses (`.htaccess`) or a write it does not permit (a
  read-only share, a full quota) `403 AccessDenied` — all of these used to be a `500`, or for long
  keys whatever the database made of them.
- `DeleteObjects` takes at most 1000 keys, as on S3.
- An `aws-chunked` body must decode to the size given in `x-amz-decoded-content-length`. That also
  catches an unsigned body cut off before its final chunk, which is accepted when the size fits.
- A query parameter with a numeric name (`?123=x`) crashed signature verification with a `500`.
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
- **Nextcloud 32 is dropped; the range is now 33–36.** Database results are read with
  `fetchAssociative()` and `fetchFirstColumn()`, which arrived in 33, instead of the PDO-style
  `fetch()` and `fetchAll(PDO::FETCH_COLUMN)`. PHP 8.2 stays the floor, which is what 33 and 34
  ask for.
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
