"""Fixtures for the integration suite.

Runs against a live Nextcloud with s3_api enabled; `tests/run.sh` provides
one. Every session registers its own buckets (random suffix) through the
app's settings API, and every test works under its own key prefix, so tests
neither depend on nor disturb each other and a reused instance (`KEEP=1`)
just accumulates a few more folders.
"""
import base64
import json
import os
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass

import pytest
from s3client import S3Client


def _env(name):
    value = os.environ.get(name)
    if not value:
        pytest.exit(f"{name} is not set -- run the suite through tests/run.sh", returncode=2)
    return value


# ---- nextcloud side: webdav + settings api ----------------------------------

@dataclass
class HttpResult:
    status: int
    body: bytes
    headers: dict

    def json(self):
        return json.loads(self.body)


class Nextcloud:
    """Plain HTTP against Nextcloud as one user (basic auth)."""

    def __init__(self, base_url, user, password):
        self.base_url = base_url.rstrip("/")
        self.user = user
        token = base64.b64encode(f"{user}:{password}".encode()).decode()
        self.auth = f"Basic {token}"

    def http(self, method, path, body=None, headers=None, auth=True):
        h = {"OCS-APIRequest": "true"}
        if auth:
            h["Authorization"] = self.auth
        h.update(headers or {})
        req = urllib.request.Request(self.base_url + path, data=body, method=method, headers=h)
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                return HttpResult(resp.status, resp.read(), dict(resp.headers))
        except urllib.error.HTTPError as e:
            return HttpResult(e.code, e.read(), dict(e.headers))

    # settings api. OCS-APIRequest lets basic auth through the CSRF check.
    def api(self, method, path, payload=None, auth=True):
        body = json.dumps(payload).encode() if payload is not None else None
        return self.http(method, "/index.php/apps/s3_api/api/v1" + path, body,
                         {"Content-Type": "application/json", "Accept": "application/json"}, auth=auth)

    # webdav
    def dav_path(self, path):
        return f"/remote.php/dav/files/{self.user}/" + urllib.parse.quote(path.strip("/"))

    def mkcol(self, path):
        r = self.http("MKCOL", self.dav_path(path))
        assert r.status in (201, 405), f"MKCOL {path}: {r.status}"
        return r

    def mkdirs(self, path):
        parts = path.strip("/").split("/")
        for i in range(1, len(parts) + 1):
            self.mkcol("/".join(parts[:i]))

    def upload(self, path, data):
        r = self.http("PUT", self.dav_path(path), data)
        assert r.status in (201, 204), f"PUT {path}: {r.status}"
        return r

    def download(self, path):
        return self.http("GET", self.dav_path(path))

    def exists(self, path):
        r = self.http("PROPFIND", self.dav_path(path), headers={"Depth": "0"})
        return r.status == 207

    def remove(self, path):
        return self.http("DELETE", self.dav_path(path))

    def trash(self):
        """Original locations of everything in the trash bin, relative to the
        user's files like the other paths here."""
        body = (b'<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
                b"<d:prop><nc:trashbin-original-location/></d:prop></d:propfind>")
        r = self.http("PROPFIND", f"/remote.php/dav/trashbin/{self.user}/trash", body, {"Depth": "1"})
        assert r.status == 207, f"PROPFIND trash: {r.status}"
        locations = ET.fromstring(r.body).iter("{http://nextcloud.org/ns}trashbin-original-location")
        return [e.text.lstrip("/") for e in locations if e.text]

    # buckets and keys
    def create_bucket(self, folder, name):
        r = self.api("POST", "/buckets", {"folderPath": folder, "bucketName": name})
        assert r.status == 201, f"create bucket {name}: {r.status} {r.body!r}"
        return r.json()

    def create_key(self, bucket_id, permission="readwrite", label=None):
        payload = {"permission": permission}
        if label:
            payload["label"] = label
        r = self.api("POST", f"/buckets/{bucket_id}/keys", payload)
        assert r.status == 201, f"create key: {r.status} {r.body!r}"
        return r.json()


@dataclass
class Bucket:
    name: str
    folder: str
    id: int


@dataclass
class Key:
    access: str
    secret: str
    id: int


@dataclass
class Env:
    base_url: str
    endpoint: str
    run_id: str
    main: Bucket
    other: Bucket
    rw: Key
    ro: Key
    other_rw: Key

    def client(self, key, endpoint=None):
        return S3Client(endpoint or self.endpoint, key.access, key.secret)


@pytest.fixture(scope="session")
def base_url():
    return _env("S3T_BASE_URL").rstrip("/")


@pytest.fixture(scope="session")
def nc(base_url):
    return Nextcloud(base_url, _env("S3T_ADMIN_USER"), _env("S3T_ADMIN_PASSWORD"))


@pytest.fixture(scope="session")
def nc_bob(base_url):
    return Nextcloud(base_url, _env("S3T_BOB_USER"), _env("S3T_BOB_PASSWORD"))


@pytest.fixture(scope="session")
def env(nc, base_url):
    run_id = secrets.token_hex(3)

    def bucket(role):
        folder = f"s3t-{run_id}-{role}"
        nc.mkcol(folder)
        data = nc.create_bucket("/" + folder, f"{role}-{run_id}")
        return Bucket(data["bucketName"], folder, data["id"])

    def key(b, permission):
        data = nc.create_key(b.id, permission, label=f"pytest {permission}")
        return Key(data["accessKey"], data["secretKey"], data["id"])

    main, other = bucket("main"), bucket("other")
    return Env(
        base_url=base_url,
        endpoint=base_url + "/apps/s3_api/s3",
        run_id=run_id,
        main=main,
        other=other,
        rw=key(main, "readwrite"),
        ro=key(main, "readonly"),
        other_rw=key(other, "readwrite"),
    )


@pytest.fixture(scope="session")
def s3(env):
    """Raw client, read-write on the main bucket."""
    return env.client(env.rw)


@pytest.fixture(scope="session")
def s3_ro(env):
    return env.client(env.ro)


@pytest.fixture(scope="session")
def bucket(env):
    return env.main.name


def _boto(env, key, **config):
    import boto3
    from botocore.config import Config

    cfg = {"signature_version": "s3v4", "s3": {"addressing_style": "path"},
           "retries": {"max_attempts": 1, "mode": "standard"}}
    cfg.update(config)
    return boto3.client(
        "s3",
        endpoint_url=env.endpoint,
        aws_access_key_id=key.access,
        aws_secret_access_key=key.secret,
        region_name="us-east-1",
        config=Config(**cfg),
    )


@pytest.fixture(scope="session")
def boto(env):
    return _boto(env, env.rw)


@pytest.fixture(scope="session")
def boto_ro(env):
    return _boto(env, env.ro)


@pytest.fixture(scope="session")
def boto_factory(env):
    return lambda key=None, **config: _boto(env, key or env.rw, **config)


@pytest.fixture
def prefix(request):
    """A key prefix nobody else uses, ending in `/`."""
    name = re.sub(r"[^a-z0-9]+", "-", request.node.name.lower()).strip("-")[:40]
    return f"{name}-{secrets.token_hex(3)}/"


def put_with_retry(client, bucket, key, body=b"x", attempts=6, **kw):
    """PUT, retrying 503 SlowDown the way every S3 client does.

    Parallel writes into one folder contend for Nextcloud's file locks, which
    the app reports as SlowDown rather than failing.
    """
    for attempt in range(attempts):
        r = client.put(bucket, key, body, **kw)
        if r.status != 503:
            return r
        time.sleep(0.2 * (attempt + 1))
    return r


def put_many(client, bucket, keys, body=b"x", workers=6):
    def one(k):
        r = put_with_retry(client, bucket, k, body)
        assert r.status == 200, (k, r.status, r.body)

    with ThreadPoolExecutor(workers) as pool:
        list(pool.map(one, keys))


@pytest.fixture(scope="session")
def many_keys(s3, bucket):
    """1001 objects under one prefix: one more than a default listing page.

    Shared, because creating them is the slow part. Read-only for users.
    """
    p = f"many-{secrets.token_hex(3)}/"
    keys = [f"{p}k{i:04d}" for i in range(1001)]
    put_many(s3, bucket, keys)
    return p, keys


@pytest.fixture(scope="session")
def container():
    """Run a command in the Nextcloud container as www-data, return stdout.

    For what no HTTP API reaches: occ, the data directory, the database.
    """
    name = os.environ.get("S3T_CONTAINER")
    engine = os.environ.get("S3T_ENGINE", "podman")
    if not name:
        pytest.skip("no container to run commands in")

    def run(*args):
        return subprocess.run([engine, "exec", "-u", "www-data", name, *args],
                              capture_output=True, text=True, check=True).stdout

    return run


@pytest.fixture(scope="session")
def occ(container):
    return lambda *args: container("php", "occ", *args)


@pytest.fixture(scope="session")
def datadir(occ):
    return occ("config:system:get", "datadirectory").strip()


# One prepared statement against SQLite, parameters as JSON. Rows come back
# as a list of dicts, anything else as the number of rows it touched.
_SQL = r"""
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_TIMEOUT, 30);
$stmt = $db->prepare($argv[2]);
$stmt->execute(json_decode($argv[3], true));
echo json_encode($stmt->columnCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->rowCount());
"""


@pytest.fixture(scope="session")
def sql(container, occ, datadir):
    """Run SQL against the instance's database.

    For state the API cannot produce on demand: rows old enough for the
    cleanup job, a lock left behind by a request that died. Tables carry the
    default `oc_` prefix. tests/run.sh sets the instance up on SQLite; on
    anything else this skips.
    """
    if occ("config:system:get", "dbtype").strip() != "sqlite3":
        pytest.skip("needs the SQLite database tests/run.sh sets up")
    path = f"{datadir}/{occ('config:system:get', 'dbname').strip()}.db"

    def run(statement, *params):
        return json.loads(container("php", "-r", _SQL, "--", path, statement, json.dumps(params)))

    return run
