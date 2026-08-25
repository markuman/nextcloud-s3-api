# Nextcloud S3 API

A Nextcloud app that exposes folders as S3-compatible buckets.

## How it works

Users can register any Nextcloud folder as an S3 bucket in their personal settings and create API keys (readonly/readwrite) per bucket. The bucket is then accessible via a standard S3-compatible API — e.g. with the AWS CLI, boto3, or any other S3 client.

## Requirements

- Nextcloud 30–34
- PHP 8.1+ (as required by Nextcloud)

## Installation

Copy the `s3_api` folder into the Nextcloud `apps/` directory and enable the app:

```bash
cp -r s3_api /var/www/nextcloud/apps/
php occ app:enable s3_api
php occ upgrade          # creates the multipart upload table
```

## Setup

1. In Nextcloud go to **Settings → Personal → S3 API**, register a folder as a bucket and create an API key.
2. Note down the Access Key and Secret Key.

## Usage

### Endpoint

```
https://<nextcloud-host>/apps/s3_api/s3
```

Only path-style addressing is supported (`<endpoint>/<bucket>/<key>`), because a
Nextcloud instance cannot serve per-bucket subdomains. Most clients need to be
told this explicitly — see the examples below.

> **Trailing slash:** some SDKs (notably `aws-sdk-rust`) append the bucket to the
> endpoint without inserting a separator, turning `…/s3` into `…/s3my-bucket` and
> failing with 404 on every request. If your client does no path normalisation,
> configure the endpoint as `https://<host>/apps/s3_api/s3/`. The AWS CLI and
> boto3 accept either form.

### AWS CLI example

```bash
aws s3 ls s3://my-bucket \
  --endpoint-url https://<nextcloud-host>/apps/s3_api/s3 \
  --region us-east-1
```

Add credentials to `~/.aws/credentials`:

```ini
[nextcloud]
aws_access_key_id     = <access-key>
aws_secret_access_key = <secret-key>
```

### Python (boto3) example

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

## Using it as a walgit store

[walgit](https://github.com/tobi/walgit) keeps git history in an object store and
uses conditional writes on `manifest.pb` as its commit point, so it exercises
considerably more of the S3 API than a plain file sync: compare-and-swap,
conditional reads, ranged reads, presigned URLs, delimited listings and
server-side copy. Those are implemented, and walgit's backend contract suite
(`crates/walgit-store/tests/contract.rs`) passes against this app.

`walgit.toml`:

```toml
[store]
backend = "s3"
bucket = "gittest"              # the bucket name registered in Nextcloud
prefix = ""                     # optional key prefix inside the bucket
max_retries = 8

# Nextcloud is not a high-throughput object store and every part is buffered
# server-side, so keep parts modest rather than the 64/32 MiB defaults.
multipart_threshold = "32MiB"
multipart_part_size = "8MiB"

[store.s3]
# Note the trailing slash: aws-sdk-rust does not add a separator before the
# bucket name.
endpoint = "https://<nextcloud-host>/apps/s3_api/s3/"
region = "us-east-1"
access_key_env = "AWS_ACCESS_KEY_ID"
secret_key_env = "AWS_SECRET_ACCESS_KEY"
force_path_style = true         # required: no per-bucket subdomains

[server]
listen = "127.0.0.1:8080"

[cache]
dir = "/var/lib/walgit"

[bundles]
# Bundle URIs are composed in the bucket with UploadPartCopy, which works, but
# the bytes still pass through the Nextcloud PHP process. Serve them by proxy
# rather than handing clients presigned URLs.
serve_via = "proxy"

[lfs]
serve_via = "proxy"
```

Credentials come from the environment, not the config file:

```bash
export AWS_ACCESS_KEY_ID=<access-key>
export AWS_SECRET_ACCESS_KEY=<secret-key>
walgit serve --config walgit.toml
```

The API key must have `readwrite` permission — walgit writes on every push and
takes leases during maintenance.

### Caveats

- **One writer.** Conditional writes are checked and applied without holding a
  lock across both steps, so two walgit instances pushing to the same bucket at
  the same instant can both win a `Create`. A single instance is safe.
- **Throughput.** Every request goes through PHP and Nextcloud's storage layer.
  Fine for ordinary repositories; not a substitute for a real object store in
  front of a large monorepo.
- **Upload size.** Single-shot uploads are bounded by `post_max_size`,
  `upload_max_filesize` and the web server's body limit (nginx
  `client_max_body_size`). Multipart uploads sidestep this, which is why the
  threshold above is lowered.

## Supported S3 operations

| Operation                | Method | Notes                                                     |
|--------------------------|--------|-----------------------------------------------------------|
| ListBuckets              | GET    |                                                           |
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

Any other subresource (`?acl`, `?tagging`, `?lifecycle`, …) is answered with
`NotImplemented` rather than being ignored — an ignored subresource would
otherwise be handled as an operation on the object itself.

### Semantics worth knowing

- **ETags** are Nextcloud's own etags, not content MD5. They change whenever the
  content changes and are stable across `GET`, `HEAD`, `PUT` and listings, which
  is what conditional requests need, but do not compute them client-side. For
  multipart objects the usual `<md5>-<parts>` form is returned.
- **Conditional writes** return `412 PreconditionFailed`. `If-None-Match: *`
  means create-only; `If-Match: <etag>` means replace exactly that version.
- **Streaming.** Bodies are decoded and written as a stream, so uploads are not
  held in memory. `Content-Encoding: aws-chunked` with a trailing checksum (the
  default for current AWS SDKs) is decoded rather than stored verbatim.
- **Multipart parts** live in a hidden `.s3-uploads/` folder inside the bucket
  while an upload is in flight and are excluded from listings.
- **Keys map to paths.** A key containing `/` creates folders, so `a/b` and `a`
  cannot both be objects.

## Authentication

Requests are authenticated with **AWS Signature V4**, either through the
`Authorization` header or as a presigned URL (`X-Amz-Signature` in the query
string). Requests older than 15 minutes are rejected, as are presigned URLs past
their expiry.

Each API key is bound to exactly one bucket and is either `readonly` or
`readwrite`.

## License

AGPL-3.0
