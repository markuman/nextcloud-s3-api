# Nextcloud S3 API

A Nextcloud app that exposes folders as S3-compatible buckets.

## How it works

Users can register any Nextcloud folder as an S3 bucket in their personal settings and create API keys (readonly/readwrite) per bucket. The bucket is then accessible via a standard S3-compatible API — e.g. with the AWS CLI, boto3, or any other S3 client.

## Requirements

- Nextcloud 28–31
- PHP (as required by Nextcloud)

## Installation

Copy the `s3_api` folder into the Nextcloud `apps/` directory and enable the app:

```bash
cp -r s3_api /var/www/nextcloud/apps/
php occ app:enable s3_api
```

## Setup

1. In Nextcloud go to **Settings → Personal → S3 API**, register a folder as a bucket and create an API key.
2. Note down the Access Key and Secret Key.

## Usage

### Endpoint

```
https://<nextcloud-host>/apps/s3_api/s3
```

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

s3 = boto3.client(
    "s3",
    endpoint_url="https://<nextcloud-host>/apps/s3_api/s3",
    aws_access_key_id="<access-key>",
    aws_secret_access_key="<secret-key>",
    region_name="us-east-1",
)

s3.upload_file("file.txt", "my-bucket", "file.txt")
```

## Supported S3 operations

| Operation        | Method | Description                  |
|------------------|--------|------------------------------|
| ListBuckets      | GET    | List all own buckets         |
| ListObjects (v1) | GET    | List objects in a bucket     |
| ListObjects (v2) | GET    | List objects in a bucket     |
| GetObject        | GET    | Download a file              |
| HeadObject       | HEAD   | Retrieve object metadata     |
| PutObject        | PUT    | Upload a file                |
| DeleteObject     | DELETE | Delete a file                |

## Authentication

All requests are authenticated using **AWS Signature V4**. Each API key is bound to exactly one bucket and has either `readonly` or `readwrite` permission.

## License

AGPL-3.0  
Written by Claude Opus 4.6
