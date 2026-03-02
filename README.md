# Nextcloud S3 API

Eine Nextcloud-App, die Ordner als S3-kompatible Buckets bereitstellt.

## Funktionsweise

Nutzer können in den persönlichen Einstellungen beliebige Nextcloud-Ordner als S3-Bucket registrieren und pro Bucket API-Keys (readonly/readwrite) erstellen. Der Bucket ist dann über eine S3-kompatible API erreichbar – z. B. mit dem AWS CLI, boto3 oder jedem anderen S3-Client.

## Voraussetzungen

- Nextcloud 28–31
- PHP (wie von Nextcloud vorausgesetzt)

## Installation

Den Ordner `s3_api` in das Nextcloud `apps/`-Verzeichnis legen und die App aktivieren:

```bash
cp -r s3_api /var/www/nextcloud/apps/
php occ app:enable s3_api
```

## Einrichtung

1. In Nextcloud unter **Einstellungen → Persönlich → S3 API** einen Ordner als Bucket registrieren und einen API-Key anlegen.
2. Access Key und Secret Key notieren.

## Verwendung

### Endpoint

```
https://<nextcloud-host>/apps/s3_api/s3
```

### AWS CLI Beispiel

```bash
aws s3 ls s3://mein-bucket \
  --endpoint-url https://<nextcloud-host>/apps/s3_api/s3 \
  --region us-east-1
```

Credentials in `~/.aws/credentials` eintragen:

```ini
[nextcloud]
aws_access_key_id     = <access-key>
aws_secret_access_key = <secret-key>
```

### Python (boto3) Beispiel

```python
import boto3

s3 = boto3.client(
    "s3",
    endpoint_url="https://<nextcloud-host>/apps/s3_api/s3",
    aws_access_key_id="<access-key>",
    aws_secret_access_key="<secret-key>",
    region_name="us-east-1",
)

s3.upload_file("datei.txt", "mein-bucket", "datei.txt")
```

## Unterstützte S3-Operationen

| Operation         | Methode | Beschreibung                  |
|-------------------|---------|-------------------------------|
| ListBuckets       | GET     | Alle eigenen Buckets auflisten |
| ListObjects (v1)  | GET     | Objekte im Bucket auflisten   |
| ListObjects (v2)  | GET     | Objekte im Bucket auflisten   |
| GetObject         | GET     | Datei herunterladen           |
| HeadObject        | HEAD    | Metadaten abrufen             |
| PutObject         | PUT     | Datei hochladen               |
| DeleteObject      | DELETE  | Datei löschen                 |

## Authentifizierung

Alle Anfragen werden per **AWS Signature V4** authentifiziert. Jeder API-Key ist an genau einen Bucket gebunden und hat entweder `readonly`- oder `readwrite`-Berechtigung.

## Lizenz

AGPL-3.0
