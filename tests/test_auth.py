"""SigV4 verification, key scoping and permissions."""
import datetime
import time
import urllib.error
import urllib.request

import pytest
from botocore.exceptions import ClientError
from s3client import EMPTY_SHA256, S3Client


def stamp(delta=0):
    t = datetime.datetime.now(datetime.UTC) + datetime.timedelta(seconds=delta)
    return t.strftime("%Y%m%dT%H%M%SZ")


def test_missing_authorization(s3, bucket, prefix):
    r = s3.get(bucket, prefix + "x", auth=False)
    assert r.status == 403
    assert r.error_code == "AccessDenied"


def test_unknown_access_key(env, bucket, prefix):
    r = S3Client(env.endpoint, "NOSUCHKEY0000000000X", "whatever").get(bucket, prefix + "x")
    assert r.status == 403
    assert r.error_code == "InvalidAccessKeyId"


def test_wrong_secret(s3, bucket, prefix):
    r = s3.get(bucket, prefix + "x", secret="not-the-secret")
    assert r.status == 403
    assert r.error_code == "SignatureDoesNotMatch"


def test_signature_covers_the_path(s3, bucket, prefix):
    """A signature for one key must not open another."""
    s3.put(bucket, prefix + "secret", b"x")
    amz = stamp()
    headers = {"Host": s3.host, "x-amz-date": amz, "x-amz-content-sha256": EMPTY_SHA256}
    headers["Authorization"], _ = s3.sign("GET", s3.uri(bucket, prefix + "public"), "", headers, EMPTY_SHA256, amz)
    r = s3.send("GET", s3.uri(bucket, prefix + "secret"), b"", headers)
    assert r.status == 403
    assert r.error_code == "SignatureDoesNotMatch"


def test_signature_covers_the_query(s3, bucket, prefix):
    r = s3.request("GET", bucket, query={"list-type": "2", "prefix": prefix},
                   raw_query="list-type=2&prefix=")
    assert r.status == 403
    assert r.error_code == "SignatureDoesNotMatch"


def test_extra_signed_headers(s3, bucket, prefix):
    r = s3.put(bucket, prefix + "x", b"x", headers={"Content-Type": "text/plain", "If-None-Match": "*"},
               signed=["content-type", "host", "if-none-match", "x-amz-content-sha256", "x-amz-date"])
    assert r.status == 200


@pytest.mark.parametrize("delta", [-16 * 60, 16 * 60])
def test_clock_skew(s3, bucket, prefix, delta):
    r = s3.get(bucket, prefix + "x", amz_date=stamp(delta))
    assert r.status == 403
    assert r.error_code == "RequestTimeTooSkewed"


def test_skew_within_tolerance(s3, bucket, prefix):
    s3.put(bucket, prefix + "x", b"x")
    assert s3.get(bucket, prefix + "x", amz_date=stamp(-10 * 60)).status == 200


def test_scope_date_must_match_request_date(s3, bucket, prefix):
    # Sign with a scope from yesterday but send today's x-amz-date.
    amz = stamp()
    yesterday = stamp(-86400)
    headers = {"Host": s3.host, "x-amz-date": amz, "x-amz-content-sha256": EMPTY_SHA256}
    auth, _ = s3.sign("GET", s3.uri(bucket, prefix + "x"), "", headers, EMPTY_SHA256, amz)
    headers["Authorization"] = auth.replace(f"/{amz[:8]}/", f"/{yesterday[:8]}/")
    r = s3.send("GET", s3.uri(bucket, prefix + "x"), b"", headers)
    assert r.status == 403
    assert r.error_code == "AccessDenied"


def test_payload_hash_mismatch(s3, bucket, prefix):
    r = s3.put(bucket, prefix + "x", b"actual body", payload_hash="0" * 64)
    assert r.status == 400
    assert r.error_code == "XAmzContentSHA256Mismatch"
    assert s3.get(bucket, prefix + "x").status == 404


def test_unsigned_payload(s3, bucket, prefix):
    r = s3.put(bucket, prefix + "x", b"unsigned", payload_hash="UNSIGNED-PAYLOAD")
    assert r.status == 200
    assert s3.get(bucket, prefix + "x").body == b"unsigned"


def test_other_region_in_scope(env, bucket, prefix):
    client = S3Client(env.endpoint, env.rw.access, env.rw.secret, region="eu-central-1")
    assert client.put(bucket, prefix + "x", b"x").status == 200


def test_index_php_endpoint(env, bucket, prefix):
    """The endpoint also answers behind /index.php, for servers without
    pretty URLs. The signature then covers that path."""
    client = env.client(env.rw, endpoint=env.base_url + "/index.php/apps/s3_api/s3")
    assert client.put(bucket, prefix + "x", b"via index.php").status == 200
    assert client.get(bucket, prefix + "x").body == b"via index.php"


def test_trailing_slash_endpoint(env, bucket, prefix):
    """aws-sdk-rust style: endpoint configured with a trailing slash."""
    client = env.client(env.rw, endpoint=env.endpoint + "/")
    assert client.put(bucket, prefix + "x", b"x").status == 200


# ---- scoping ----------------------------------------------------------------

def test_key_is_scoped_to_its_bucket(s3, env, prefix):
    r = s3.get(env.other.name, prefix + "x")
    assert r.status == 403
    assert r.error_code == "AccessDenied"
    assert s3.put(env.other.name, prefix + "x", b"x").status == 403


def test_unknown_bucket(s3, prefix):
    r = s3.get("no-such-bucket-here", prefix + "x")
    assert r.status == 404
    assert r.error_code == "NoSuchBucket"


def test_bucket_names_are_per_user(nc_bob, env, prefix):
    """bob registering a bucket with the same name does not make admin's key
    reach bob's files, nor bob's key reach admin's."""
    nc_bob.mkcol(f"bob-{env.run_id}")
    bucket = nc_bob.create_bucket(f"/bob-{env.run_id}", env.main.name)
    key = nc_bob.create_key(bucket["id"])
    bob = S3Client(env.endpoint, key["accessKey"], key["secretKey"])

    assert bob.put(env.main.name, prefix + "x", b"bob's").status == 200
    admin = env.client(env.rw)
    assert admin.get(env.main.name, prefix + "x").status == 404
    nc_bob.api("DELETE", f"/buckets/{bucket['id']}")


# ---- read-only keys ---------------------------------------------------------

def test_readonly_can_read(s3, s3_ro, bucket, prefix):
    s3.put(bucket, prefix + "x", b"x")
    assert s3_ro.get(bucket, prefix + "x").body == b"x"
    assert s3_ro.head(bucket, prefix + "x").status == 200
    assert prefix + "x" in s3_ro.list_all(bucket, prefix)


@pytest.mark.parametrize("op", ["put", "delete", "bulk-delete", "copy", "multipart"])
def test_readonly_cannot_write(s3, s3_ro, bucket, prefix, op):
    s3.put(bucket, prefix + "x", b"original")
    r = {
        "put": lambda: s3_ro.put(bucket, prefix + "x", b"changed"),
        "delete": lambda: s3_ro.delete(bucket, prefix + "x"),
        "bulk-delete": lambda: s3_ro.request(
            "POST", bucket, body=f"<Delete><Object><Key>{prefix}x</Key></Object></Delete>".encode(),
            query={"delete": ""}),
        "copy": lambda: s3_ro.put(bucket, prefix + "x", headers={"x-amz-copy-source": f"{bucket}/{prefix}x"}),
        "multipart": lambda: s3_ro.request("POST", bucket, prefix + "x", query={"uploads": ""}),
    }[op]()
    assert r.status == 403
    assert r.error_code == "AccessDenied"
    assert s3.get(bucket, prefix + "x").body == b"original"


def test_readonly_cannot_touch_foreign_uploads(boto, s3_ro, bucket, prefix):
    key = prefix + "big"
    upload_id = boto.create_multipart_upload(Bucket=bucket, Key=key)["UploadId"]
    try:
        for method, query in (("PUT", {"uploadId": upload_id, "partNumber": "1"}),
                              ("POST", {"uploadId": upload_id}),
                              ("DELETE", {"uploadId": upload_id})):
            r = s3_ro.request(method, bucket, key, b"<x/>" if method == "POST" else b"", query=query)
            assert r.status == 403, method
    finally:
        boto.abort_multipart_upload(Bucket=bucket, Key=key, UploadId=upload_id)


# ---- presigned URLs -----------------------------------------------------------

def fetch(url, method="GET", data=None):
    req = urllib.request.Request(url, method=method, data=data)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def test_presigned_get(boto, s3, bucket, prefix):
    s3.put(bucket, prefix + "x", b"shared")
    url = boto.generate_presigned_url("get_object", Params={"Bucket": bucket, "Key": prefix + "x"}, ExpiresIn=60)
    assert fetch(url) == (200, b"shared")


def test_presigned_put(boto, s3, bucket, prefix):
    url = boto.generate_presigned_url("put_object", Params={"Bucket": bucket, "Key": prefix + "x"}, ExpiresIn=60)
    status, _ = fetch(url, "PUT", b"uploaded")
    assert status == 200
    assert s3.get(bucket, prefix + "x").body == b"uploaded"


def test_presigned_expired(boto, s3, bucket, prefix):
    s3.put(bucket, prefix + "x", b"x")
    url = boto.generate_presigned_url("get_object", Params={"Bucket": bucket, "Key": prefix + "x"}, ExpiresIn=1)
    time.sleep(2)
    status, body = fetch(url)
    assert status == 403
    assert b"<Code>AccessDenied</Code>" in body


def test_presigned_expiry_is_capped(boto, s3, bucket, prefix):
    s3.put(bucket, prefix + "x", b"x")
    url = boto.generate_presigned_url("get_object", Params={"Bucket": bucket, "Key": prefix + "x"},
                                      ExpiresIn=8 * 24 * 3600)
    status, body = fetch(url)
    assert status == 403
    assert b"AuthorizationQueryParametersError" in body


def test_presigned_tampered(boto, s3, bucket, prefix):
    s3.put(bucket, prefix + "a", b"a")
    s3.put(bucket, prefix + "b", b"b")
    url = boto.generate_presigned_url("get_object", Params={"Bucket": bucket, "Key": prefix + "a"}, ExpiresIn=60)
    status, _ = fetch(url.replace(f"{prefix}a?", f"{prefix}b?"))
    assert status == 403


def test_boto3_error_codes(boto_ro, bucket, prefix):
    with pytest.raises(ClientError) as e:
        boto_ro.put_object(Bucket=bucket, Key=prefix + "x", Body=b"x")
    assert e.value.response["Error"]["Code"] == "AccessDenied"
