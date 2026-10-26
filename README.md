<div align="center">

# 🪣 S3 API for Nextcloud

**Point any S3 client at a folder you already have.**

*No object store · No duplication · Your files stay files*

[![Nextcloud 33–36](https://img.shields.io/badge/Nextcloud-33--36-0082c9)](https://nextcloud.com)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net)

<br>

<img src="s3_api/img/1-settings.png" alt="The personal settings page showing a registered bucket, its API key and the forms to add more" width="900">

</div>

---

## What it does

Mark any Nextcloud folder as a bucket, create an API key, point `aws s3` at it. That's the whole
idea.

The files stay **ordinary Nextcloud files** — visible in the web UI, synced by the desktop client,
shareable, versioned, backed up along with everything else. There is no second copy in an object
store and no import step. A file a colleague drops into the shared folder is an object your script
can `GET` a second later.

```bash
aws s3 cp backup.tar.zst s3://my-bucket/ \
  --endpoint-url https://cloud.example.com/apps/s3_api/s3
```

---

## ✨ Why this one is different

Most "S3 for X" bridges implement the four calls a file sync needs and stop there. This one
implements the parts that let a real tool use it as a backend:

<table>
<tr><td width="50%" valign="top">

### 🔒 Conditional writes that actually hold
`If-Match` / `If-None-Match` with **serialised concurrent writers**, so exactly one of a set of
racing writes wins. That makes the store usable as a compare-and-swap primitive, not just a
key-value dump.

</td><td width="50%" valign="top">

### 🧩 Multipart, including compose
Create, upload, copy parts, complete, abort, list. `UploadPartCopy` gives you server-side compose —
stitching objects together without a round trip through the client.

</td></tr>
<tr><td valign="top">

### 📐 Correct ETags
Content MD5 for single-part, the compound `<md5-of-part-md5s>-<parts>` form for multipart.
Deliberately **not** Nextcloud's internal etag — see below for why that one is dangerous.

</td><td valign="top">

### 🌊 Real streaming
Bodies are decoded and written as a stream, never buffered in memory. `aws-chunked` payloads have
their **per-chunk signature chain verified**, which is what binds the uploaded bytes to the
credential.

</td></tr>
</table>

Plus ranged reads, conditional reads, delimited listings, presigned URLs and batch deletes.

> ⚠️ **Throughput.** Every request goes through PHP and Nextcloud's storage layer. This is a
> convenient way to talk S3 to data you already keep in Nextcloud — not a replacement for a real
> object store in front of a large workload.

---

## 🚀 Quick start

```bash
cp -r s3_api /var/www/nextcloud/apps/
sudo -u www-data php occ app:enable s3_api
```

Then in Nextcloud: **Settings → Personal → S3 API** → register a folder as a bucket → create an
API key. Note down both keys; the secret is shown once.

To roll the app out to many users at once, an admin can pre-create the bucket configs (and, with
`--create-folder`, the folders) from the command line. No API keys are created — each user adds
their own on the bucket that then shows up in their settings:

```bash
occ s3api:bucket:create --user alice --folder /S3 --name files --create-folder
occ s3api:bucket:create --group staff --folder /S3 --name files --create-folder   # every member
```

A group covers its members at the time the command runs. Re-running it skips users whose bucket
already serves that folder; any other clash (same name on another folder, overlapping buckets) is
reported per user and nothing is created for them. An incoming share at the path is refused
unless `--allow-shared` is given, and `--folder /` (the whole home) is always refused. Avoid a folder name the skeleton files also use (e.g.
`/Documents`): for users who have never logged in, the skeleton is copied on their first login, into
the bucket's folder if it has the same name.

Buckets and keys can also be managed from scripts — with the
[`markuman.nextcloud`](https://github.com/markuman/markuman.nextcloud) Ansible collection or the
HTTP API behind the settings page, see [Managing buckets and keys
programmatically](#-managing-buckets-and-keys-programmatically).

<details>
<summary><b>Requirements and update notes</b></summary>

<br>

* Nextcloud 33–36, PHP 8.2+ — Nextcloud 35 and 36 need 8.3, but enforce that themselves, so the
  app's own floor only has to suit the older end of the range. 36 is declared ahead of its
  release and tested against the daily master build
* **Nextcloud's cron must run** — abandoned multipart uploads are only reclaimed by a background
  job
* **Behind a reverse proxy, set `trusted_proxies`** (and `forwarded_for_headers` if needed) in
  `config.php`. Failed S3 signatures count towards Nextcloud's brute force protection, which is
  per client address; without it every request seems to come from the proxy, and a few dozen
  wrong secrets from anywhere slow down — and eventually lock out — every S3 client of the
  instance
* **Give `tempdirectory` room, ideally its own volume.** Every upload is written there in full
  before Nextcloud stores it, and a multipart upload is assembled there on completion. The app
  bounds a single body (5 GiB, and the free space of the bucket owner), but parallel uploads add
  up
* `app:enable` runs the migrations. `occ upgrade` is for core upgrades and will not touch an app.
  To update after bumping the version: `occ app:disable s3_api && occ app:enable s3_api`

</details>

---

## 🔧 Connecting a client

**Endpoint:** `https://<nextcloud-host>/apps/s3_api/s3`

Only **path-style** addressing works (`<endpoint>/<bucket>/<key>`) — a Nextcloud instance cannot
serve per-bucket subdomains. Most clients need to be told this explicitly.

> **Trailing slash:** some SDKs (notably `aws-sdk-rust`) append the bucket to the endpoint without
> a separator, turning `…/s3` into `…/s3my-bucket` and 404ing on every request. If your client
> does no path normalisation, configure `https://<host>/apps/s3_api/s3/`. The AWS CLI and boto3
> accept either form.

### AWS CLI

```ini
# ~/.aws/credentials
[nextcloud]
aws_access_key_id     = <access-key>
aws_secret_access_key = <secret-key>
```

```bash
aws s3 ls s3://my-bucket \
  --endpoint-url https://<nextcloud-host>/apps/s3_api/s3 \
  --region us-east-1
```

### boto3

```python
import boto3
from botocore.config import Config

s3 = boto3.client(
    "s3",
    endpoint_url="https://<nextcloud-host>/apps/s3_api/s3",
    aws_access_key_id="<access-key>",
    aws_secret_access_key="<secret-key>",
    region_name="us-east-1",
    config=Config(signature_version="s3v4", s3={"addressing_style": "path"}),
)

s3.upload_file("file.txt", "my-bucket", "file.txt")
```

---

## 🤖 Managing buckets and keys programmatically

Everything the settings page does — registering folders as buckets, creating and revoking API keys
— goes through a small per-user HTTP API, which works just as well from a script.

Every user manages **their own** buckets and keys, authenticated as themselves. There is no admin
endpoint to create keys for someone else, on purpose: a secret generated for another person has
already been seen by someone other than its owner. To provision bucket configs for many users,
an admin uses [`occ s3api:bucket:create`](#-quick-start); the users (or their automation) then
create keys here.

### Ansible

The [`markuman.nextcloud`](https://github.com/markuman/markuman.nextcloud) collection
([Galaxy](https://galaxy.ansible.com/ui/repo/published/markuman/nextcloud/), 35.0.0 and later)
wraps this API in modules: [`s3_bucket`](https://github.com/markuman/markuman.nextcloud/wiki/s3_bucket)
creates and deletes buckets and their keys — idempotently, keys are identified by their label —
[`s3_bucket_info`](https://github.com/markuman/markuman.nextcloud/wiki/s3_bucket_info) reads them,
and the [`s3_buckets`](https://github.com/markuman/markuman.nextcloud/wiki/s3_buckets) lookup lists
them.

```yaml
- name: Bucket with a key for the backup client
  markuman.nextcloud.s3_bucket:
    name: backup
    folder: /S3/backup
    create_folder: true
    access_keys:
      - label: restic
        permission: readwrite
  register: bucket
  no_log: true  # created_keys carries the secret, returned only on creation
```

### HTTP API

**Base URL:** `https://<nextcloud-host>/apps/s3_api/api/v1` (`/index.php/apps/s3_api/api/v1`
without pretty URLs).

**Authentication:** HTTP Basic as the user, ideally with an
[app password](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html#managing-devices)
(required anyway with two-factor authentication). Every request **must carry
`OCS-APIRequest: true`** — without it Nextcloud's CSRF check answers `412`. No credentials: `401`.

Request bodies are JSON (`Content-Type: application/json`); form-encoded works too. Responses are
JSON; errors are `{"error": "<message>"}`.

| Method   | Path                                   | Body                       | Success                         |
|----------|----------------------------------------|----------------------------|---------------------------------|
| `GET`    | `/buckets`                             |                            | `200`, list of buckets          |
| `POST`   | `/buckets`                             | `folderPath`, `bucketName` | `201`, the bucket               |
| `DELETE` | `/buckets/{id}`                        |                            | `200`, `{"status": "ok"}`       |
| `GET`    | `/buckets/{id}/keys`                   |                            | `200`, list of keys, no secrets |
| `POST`   | `/buckets/{id}/keys`                   | `permission`, `label`      | `201`, the key **with secret**  |
| `DELETE` | `/buckets/{id}/keys/{keyId}`           |                            | `200`, `{"status": "ok"}`       |

```bash
NC=https://cloud.example.com
AUTH=(-u "alice:<app-password>" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json')

# the folder has to exist; the API never creates one
curl "${AUTH[@]}" -X MKCOL "$NC/remote.php/dav/files/alice/S3"

curl "${AUTH[@]}" -X POST -d '{"folderPath": "/S3", "bucketName": "files"}' "$NC/apps/s3_api/api/v1/buckets"
# 201 {"id":16,"userId":"alice","folderPath":"/S3","bucketName":"files","createdAt":"2026-10-26T14:13:55+00:00"}

curl "${AUTH[@]}" -X POST -d '{"permission": "readwrite", "label": "restic"}' "$NC/apps/s3_api/api/v1/buckets/16/keys"
# 201 {"id":9,"bucketId":16,"accessKey":"QWIYO3CF4BRYMCDKND9J","permission":"readwrite",
#      "label":"restic","createdAt":"2026-10-26T14:13:55+00:00","secretKey":"Cll1IuhE4i6/ULwW7NQ…"}

curl "${AUTH[@]}" "$NC/apps/s3_api/api/v1/buckets/16/keys"
curl "${AUTH[@]}" -X DELETE "$NC/apps/s3_api/api/v1/buckets/16/keys/9"
```

<details>
<summary><b>Fields, rules and errors</b></summary>

<br>

**Bucket:** `id`, `userId`, `folderPath` (relative to the user's home; `GET /buckets` reports
where the folder is *now*, after renames), `bucketName`, `createdAt` (ISO 8601).

**Key:** `id`, `bucketId`, `accessKey`, `permission`, `label` (`null` when none was given),
`createdAt`, and `secretKey` in the response to `POST` only.

**Creating a bucket** — `folderPath` and `bucketName` are required strings.
* `bucketName`: 3–63 characters, lowercase letters, digits, dots and hyphens, starting and ending
  with a letter or digit, no `..`. Unique per user, not per instance.
* `folderPath`: an existing folder in the user's home, `/` for the home itself. It must not be,
  contain or lie inside the folder of another bucket of the same user.
* `400` for a missing or invalid parameter or a folder that does not exist, `409` for a name that
  is taken or an overlapping folder.

**Creating a key** — `permission` is `readonly` (default) or `readwrite`, `label` is optional, at
most 255 characters. `400` for anything else.

**Not found:** a bucket or key of another user answers `404`, exactly like one that does not
exist, so ids cannot be probed. A key id under the wrong bucket id is `404` too.

**Things to know:**
* **The secret is returned once**, in the `201` of `POST …/keys`, and cannot be read again. Lost
  it? Delete the key and create a new one.
* **Creating a key is not idempotent** — each `POST` creates another key pair. Look for your
  label in `GET …/keys` first. Labels are not required to be unique; the app does not check.
* **Keys cannot be changed.** A different permission means a new key.
* **Buckets are addressed by id.** To work with names, list them and filter.
* **Deleting a bucket deletes its keys** and leaves the folder and its files alone.
* Every create and delete is logged at info level and to `admin_audit`'s log when that app is
  enabled.

Documented since 1.7.1. The Ansible collection depends on it, so changes to it are announced in
the [changelog](CHANGELOG.md).

</details>

---

## 🐚 Using it as a gits3 backend

[gits3](https://github.com/markuman/gits3srv) is a git server in a single file: clone and push
over HTTP, bare repositories in the bucket, no database. Ref updates are conditional writes on
`packed-refs` (`If-Match`), packs go up as plain PUTs. That exercises considerably more of the S3
API than a file sync does: compare-and-swap, content ETags, ranged reads, delimited listings.

**gits3's own S3 client and a real push / clone / gc cycle run against this app in the test
suite** (`tests/test_gits3.py`, `tests/test_gits3_e2e.py`).

<details>
<summary><b>Environment</b></summary>

<br>

gits3 configures itself from the environment, there is no config file:

```bash
GITS3_ENDPOINT=https://<nextcloud-host>/apps/s3_api/s3 \
GITS3_BUCKET=gittest \
GITS3_PREFIX=repos/ \
AWS_ACCESS_KEY_ID=<access-key> \
AWS_SECRET_ACCESS_KEY=<secret-key> \
GITS3_USERS="m:<password>:rw" \
python3 gits3.py
```

The API key needs `readwrite` — every push writes packs and rewrites `packed-refs`.

</details>

<details>
<summary><b>Verifying it yourself</b></summary>

<br>

```bash
tests/run.sh -k gits3
```

drives gits3's own S3 client against the endpoint and pushes, clones and garbage-collects a real
repository through a running gits3 instance. It looks for `../gits3srv/gits3.py` (override with
`GITS3=…`) and is skipped when it is missing.

A repository in the bucket is a plain bare repo — restore it without gits3:

```bash
aws s3 sync s3://gittest/repos/foo.git ./foo.git \
  --endpoint-url https://<nextcloud-host>/apps/s3_api/s3
git clone ./foo.git
```

</details>

**Caveats for this use case:** a push is one request carrying one pack, stored as one PUT — it is
bounded by `post_max_size`, `upload_max_filesize` and the web server's body limit (nginx
`client_max_body_size`), and by the app's own limits: 5 GiB per PUT and the bucket owner's free
space. Raise the first three plus `fastcgi_read_timeout` and keep `GITS3_MAX_BODY` below them; gc
writes a pack that knows no push limit, so repack large repositories locally before the first
push. And cron has to run, or abandoned uploads are never reclaimed.

**CAS is only as good as the ETag.** gits3 fences concurrent pushes by rewriting `packed-refs`
with `If-Match`. That holds because this app computes ETags from content — Nextcloud's own etag
has one-second resolution and could accept a lost update (see below).

**`503 SlowDown` under parallel writes.** Writers that race into a folder that does not exist yet
contend for Nextcloud's file locks while creating it; the losers get `503 SlowDown` before anything
is written, so a retry is safe and normally succeeds because the folder exists by then. gits3
retries transient S3 errors, so the usual race resolves on its own.

---

## 📋 Supported operations

| Operation                | Method | Notes                                                     |
|--------------------------|--------|-----------------------------------------------------------|
| ListBuckets              | GET    | reports only the bucket the key is scoped to              |
| HeadBucket               | HEAD   |                                                           |
| GetBucketLocation        | GET    | `?location`, always `us-east-1`                           |
| GetBucketVersioning      | GET    | `?versioning`, always unversioned                         |
| ListObjects (v1)         | GET    | `prefix`, `delimiter`, `marker`, `max-keys`               |
| ListObjects (v2)         | GET    | plus `start-after`, `continuation-token`                  |
| GetObject                | GET    | `Range`, `If-Match`, `If-None-Match`                      |
| HeadObject               | HEAD   |                                                           |
| PutObject                | PUT    | `If-Match`, `If-None-Match`, `aws-chunked` bodies         |
| CopyObject               | PUT    | `x-amz-copy-source`, same bucket only                     |
| DeleteObject             | DELETE |                                                           |
| DeleteObjects            | POST   | `?delete`                                                 |
| CreateMultipartUpload    | POST   | `?uploads`                                                |
| UploadPart               | PUT    | `?uploadId&partNumber`                                    |
| UploadPartCopy           | PUT    | plus `x-amz-copy-source-range`                            |
| CompleteMultipartUpload  | POST   | `?uploadId`                                               |
| AbortMultipartUpload     | DELETE | `?uploadId`                                               |
| ListParts                | GET    | `?uploadId`                                               |
| ListMultipartUploads     | GET    | `?uploads`                                                |

Any other subresource (`?acl`, `?tagging`, `?lifecycle`, …) answers `NotImplemented` rather than
being ignored — an ignored subresource would otherwise be handled as an operation on the object
itself.

---

## 🧠 Semantics worth knowing

<details open>
<summary><b>Why not Nextcloud's own etag</b></summary>

<br>

Nextcloud's etag is `md5(mtime + inode + dev + size)`. Because mtime has one-second resolution,
**two writes of equally sized but different content within the same second share an etag** — more
than enough for a compare-and-swap to accept a lost update.

This app therefore computes S3 ETags from content: plain MD5 for single-part uploads, the compound
`<md5-of-part-md5s>-<parts>` form for multipart. Objects written outside this API (web UI, WebDAV,
sync client) get an ETag on first read. A listing hashes at most 256 MiB of such files per page;
the rest are listed with a placeholder until a later listing, `GET` or `HEAD` has computed theirs:
Nextcloud's own etag with a `-0` suffix, which marks it as not an MD5 so tools do not compare it
with the content. It is not the object's ETag either, so do not use one from a listing for
`If-Match` — `HEAD` gives the real one. Without that cap, one listing of a folder full of large
files could read terabytes.

</details>

* **Conditional writes** return `412 PreconditionFailed`. `If-None-Match: *` is create-only,
  `If-Match: <etag>` replaces exactly that version. Concurrent conditional writes to one key are
  serialised, so exactly one of a set of racing creates or updates succeeds. The lock behind
  that is a database row; should a PHP process die while holding it, conditional writes to that
  one key answer `503 SlowDown` until it counts as stale after fifteen minutes (other keys, reads
  and unconditional writes are unaffected).
* **Streaming.** Uploads are not held in memory. `Content-Encoding: aws-chunked` with a trailing
  checksum — the default for current AWS SDKs — is decoded rather than stored verbatim. Where the
  client signs each chunk (`STREAMING-AWS4-HMAC-SHA256-PAYLOAD`) the signature chain is verified,
  which is what ties the uploaded bytes to the credential: with that payload type, SigV4 itself
  covers only the headers.
* **Multipart parts** live in a hidden `.s3-uploads/` folder inside the bucket while an upload is
  in flight, excluded from listings. Uploads never completed or aborted are reclaimed after a week
  by a background job.
* **Keys map to paths.** A key containing `/` creates folders, so `a/b` and `a` cannot both be
  objects. Keys that cannot be a path as they stand are refused with `InvalidArgument`: empty,
  `.` or `..` segments, a backslash, control characters, bytes that are not UTF-8, and Unicode
  that is not in NFC (Nextcloud stores every name in NFC, so `e` + combining accent would be a
  second name for `é`). Files whose names no key can spell are left out of listings.
* **A bucket is its folder**, not the folder's path: rename or move the folder and the bucket
  follows it. Moved to the trash, the bucket answers `NoSuchBucket` until it is restored.
* **Limits.** 5 GiB per `PutObject` or `UploadPart`, as on S3, and never more than the free space
  of the bucket owner (`403 AccessDenied` otherwise, before the body is read). XML bodies
  (`DeleteObjects`, `CompleteMultipartUpload`) up to 4 MiB. At most 1000 multipart uploads in
  flight per bucket. `ListParts` and `ListMultipartUploads` page like `ListObjects`.

---

## 🔐 Authentication

**AWS Signature V4**, either via the `Authorization` header or as a presigned URL
(`X-Amz-Signature` in the query string). Requests older than 15 minutes are rejected, as are
expired presigned URLs; `X-Amz-Expires` is capped at seven days, as on AWS. `host` and every
`x-amz-*` header the request carries must be signed.

The body is bound to the signature as far as the request allows: a literal `x-amz-content-sha256`
is checked against it, and so are signed `aws-chunked` chunks. `Content-MD5` and `x-amz-checksum-*`
(as header or as `aws-chunked` trailer) are checked against the bytes on every upload; a mismatch is
`400 BadDigest` and nothing is stored. SigV4a (`AWS4-ECDSA-P256-SHA256`) is not supported, its
streaming payloads included. `DeleteObjects` additionally needs
something the signature covers to pin its body down — a literal payload hash, a signed
`Content-MD5`, or an `x-amz-checksum-*` header — as S3 requires a checksum there anyway; every SDK
sends one.

Each API key is bound to **exactly one bucket** and is either `readonly` or `readwrite`.
`readonly` reads and lists objects; multipart uploads, including listing them and their parts,
need `readwrite`. `ListBuckets` reports only that bucket — not every bucket the owning account
happens to have — and any other bucket name answers `NoSuchBucket`, whether the account has such a
bucket or not. Keys stop working when their account is disabled and are removed when it is
deleted. Wrong secrets, wrong chunk signatures and unknown keys count towards Nextcloud's brute force protection and are
logged as warnings with the access key they named. Creating and deleting buckets and keys, and
every `DeleteObjects`, are logged at info level and to `admin_audit`'s audit log when that app is
enabled.

> **Use HTTPS.** As on AWS, a signature only proves who made the request. Within its 15 minutes
> (or a presigned URL's lifetime) anyone who captured it can replay it, and the request and
> response bodies travel in the clear on plain HTTP.

---

## 🛠️ Development

<details>
<summary><b>Running the tests</b></summary>

<br>

```bash
tests/run.sh                         # Nextcloud 34, throwaway container
NC_VERSION=35 tests/run.sh -k multipart -x
NC_VERSION=master tests/run.sh       # next major, built from the daily tarball
KEEP=1 tests/run.sh                  # keep the container, reuse it next run
```

Needs `podman` (or `ENGINE=docker`), `curl` and `uv`. The script starts the official
`nextcloud:<version>-apache` image with SQLite, mounts `s3_api/` read-only, enables the app and
runs the pytest suite in `tests/` against `http://127.0.0.1:18080`. Buckets and keys are created by
the suite itself through the settings API.

The gits3 tests run [gits3](https://github.com/markuman/gits3srv)'s own S3 client and a real
push / clone / gc cycle through it. They look for `../gits3srv/gits3.py` (override with
`GITS3=…`) and are skipped when it is missing.

</details>

<details>
<summary><b>Publishing a release to the Nextcloud App Store</b></summary>

<br>

```bash
./build-release.sh --no-sign   # or without the flag once you have a certificate
```

GitHub's own release tarballs are *not* usable: they put the version into the top level folder
name, while the store requires that folder to be exactly the app id. The script builds a
conforming archive and verifies the layout.

**One-time setup:**

1. Generate a key and CSR — the CN **must** be the app id, `s3_api`:
   ```bash
   mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
   openssl req -nodes -newkey rsa:4096 -keyout s3_api.key -out s3_api.csr -subj "/CN=s3_api"
   ```
2. Open a PR with `s3_api.csr` against
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests).
   They sign it and post `s3_api.crt` back — save it next to the key.
3. Register the app id at [apps.nextcloud.com/developer/apps/new](https://apps.nextcloud.com/developer/apps/new)
   with the certificate and this signature:
   ```bash
   echo -n "s3_api" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/s3_api.key | openssl base64
   ```

**Per release:** bump `<version>` in `s3_api/appinfo/info.xml`, add a matching `## X.Y.Z` section
to `CHANGELOG.md` (the script refuses to build if they disagree — a mismatch makes the store
import an empty changelog), tag, attach the tarball to a GitHub release, then submit the URL plus
the printed signature at
[developer/apps/releases/new](https://apps.nextcloud.com/developer/apps/releases/new).

> Keep `s3_api.key` private. Losing it means revoking the certificate, and re-registering deletes
> every existing release.

</details>

---

<div align="center">

**AGPL-3.0-or-later**

*Designed by a human, generated by robots. Blame the human.*
</div>
