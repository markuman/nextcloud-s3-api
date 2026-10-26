"""What binds a request body to its signature, and how much body a request may
carry.

DeleteObjects and CompleteMultipartUpload read their XML through the same
path as uploads: decoded, bounded, and checked against whatever the request
claims about it. Up to 1.6.1 they read php://input raw and checked nothing,
so a captured DeleteObjects could be replayed within its fifteen minutes with
another list of keys.
"""
import base64
import hashlib
import xml.etree.ElementTree as ET
import zlib

import pytest
from s3client import NS

GiB = 1024 ** 3
SIGNED_WITH_MD5 = ["content-md5", "host", "x-amz-content-sha256", "x-amz-date"]


def delete_body(keys):
    objects = "".join(f"<Object><Key>{k}</Key></Object>" for k in keys)
    return f'<?xml version="1.0" encoding="UTF-8"?><Delete>{objects}</Delete>'.encode()


def bulk_delete(s3, bucket, body, **kw):
    return s3.request("POST", bucket, body=body, query={"delete": ""}, **kw)


def md5_b64(data):
    return base64.b64encode(hashlib.md5(data).digest()).decode()


def crc32_b64(data):
    return base64.b64encode(zlib.crc32(data).to_bytes(4, "big")).decode()


def crc64nvme_b64(data):
    poly, crc = 0x9A6C9329AC4BC9B5, 0xFFFFFFFFFFFFFFFF
    for byte in data:
        crc ^= byte
        for _ in range(8):
            crc = (crc >> 1) ^ poly if crc & 1 else crc >> 1
    return base64.b64encode((crc ^ 0xFFFFFFFFFFFFFFFF).to_bytes(8, "big")).decode()


@pytest.fixture
def victims(s3, bucket, prefix):
    keys = [prefix + "a", prefix + "b"]
    for k in keys:
        s3.put(bucket, k, b"keep me")
    return keys


def assert_kept(s3, bucket, keys):
    for k in keys:
        assert s3.get(bucket, k).body == b"keep me", k


# ---- DeleteObjects ------------------------------------------------------------

def test_swapped_body_is_refused(s3, bucket, victims, prefix):
    """Signed for one body, sent with another: the replay the payload hash
    exists to stop."""
    signed_for = delete_body([prefix + "harmless"])
    r = bulk_delete(s3, bucket, delete_body(victims), payload_hash=hashlib.sha256(signed_for).hexdigest())
    assert r.status == 400
    assert r.error_code == "XAmzContentSHA256Mismatch"
    assert_kept(s3, bucket, victims)


def test_unsigned_payload_without_checksum_is_refused(s3, bucket, victims):
    """S3 requires Content-MD5 or a checksum here; without one, nothing the
    signature covers says which keys were meant."""
    r = bulk_delete(s3, bucket, delete_body(victims), payload_hash="UNSIGNED-PAYLOAD")
    assert r.status == 400
    assert r.error_code == "InvalidRequest"
    assert_kept(s3, bucket, victims)


def test_unsigned_content_md5_does_not_count(s3, bucket, victims):
    """It could have been rewritten together with the body."""
    body = delete_body(victims)
    r = bulk_delete(s3, bucket, body, payload_hash="UNSIGNED-PAYLOAD", headers={"Content-MD5": md5_b64(body)})
    assert r.status == 400
    assert r.error_code == "InvalidRequest"
    assert_kept(s3, bucket, victims)


def test_signed_content_md5_binds_the_body(s3, bucket, victims):
    body = delete_body(victims)
    r = bulk_delete(s3, bucket, body, payload_hash="UNSIGNED-PAYLOAD",
                    headers={"Content-MD5": md5_b64(body)}, signed=SIGNED_WITH_MD5)
    assert r.status == 200, r.body
    assert s3.list_all(bucket, victims[0].rsplit("/", 1)[0] + "/") == []


def test_wrong_content_md5_is_refused(s3, bucket, victims, prefix):
    body = delete_body(victims)
    r = bulk_delete(s3, bucket, body, payload_hash="UNSIGNED-PAYLOAD",
                    headers={"Content-MD5": md5_b64(delete_body([prefix + "x"]))}, signed=SIGNED_WITH_MD5)
    assert r.status == 400
    assert r.error_code == "BadDigest"
    assert_kept(s3, bucket, victims)


@pytest.mark.parametrize("header,checksum", [
    ("x-amz-checksum-crc32", crc32_b64),
    ("x-amz-checksum-sha256", lambda b: base64.b64encode(hashlib.sha256(b).digest()).decode()),
    ("x-amz-checksum-crc64nvme", crc64nvme_b64),
], ids=["crc32", "sha256", "crc64nvme"])
def test_checksum_header_binds_the_body(s3, bucket, victims, prefix, header, checksum):
    body = delete_body(victims)

    r = bulk_delete(s3, bucket, body, payload_hash="UNSIGNED-PAYLOAD",
                    headers={header: checksum(delete_body([prefix + "x"]))})
    assert r.status == 400
    assert r.error_code == "BadDigest"
    assert_kept(s3, bucket, victims)

    r = bulk_delete(s3, bucket, body, payload_hash="UNSIGNED-PAYLOAD", headers={header: checksum(body)})
    assert r.status == 200, r.body
    assert sorted(d.findtext(f"{NS}Key") for d in r.xml().iter(f"{NS}Deleted")) == sorted(victims)


def test_oversized_xml_is_refused_unparsed(s3, bucket, victims):
    body = delete_body(victims) + b" " * (4 * 1024 * 1024)
    r = bulk_delete(s3, bucket, body)
    assert r.status == 400
    assert r.error_code == "MaxMessageLengthExceeded"
    assert_kept(s3, bucket, victims)


# ---- CompleteMultipartUpload --------------------------------------------------

@pytest.fixture
def one_part_upload(s3, boto, bucket, prefix):
    key = prefix + "big.bin"
    r = s3.request("POST", bucket, key, query={"uploads": ""}, raw_query="uploads")
    upload_id = r.xml().findtext(f"{NS}UploadId")
    etag = s3.put(bucket, key, b"only part", query={"partNumber": "1", "uploadId": upload_id}).etag
    yield key, upload_id, etag
    try:
        boto.abort_multipart_upload(Bucket=bucket, Key=key, UploadId=upload_id)
    except Exception:
        pass


def complete_body(parts):
    return ("<CompleteMultipartUpload>"
            + "".join(f"<Part><PartNumber>{n}</PartNumber><ETag>{e}</ETag></Part>" for n, e in parts)
            + "</CompleteMultipartUpload>").encode()


def test_complete_with_swapped_body_is_refused(s3, bucket, one_part_upload):
    key, upload_id, etag = one_part_upload
    body = complete_body([(1, etag)])
    r = s3.request("POST", bucket, key, body, query={"uploadId": upload_id},
                   payload_hash=hashlib.sha256(b"<CompleteMultipartUpload/>").hexdigest())
    assert r.status == 400
    assert r.error_code == "XAmzContentSHA256Mismatch"
    assert s3.get(bucket, key).status == 404

    r = s3.request("POST", bucket, key, body, query={"uploadId": upload_id})
    assert r.status == 200, r.body


def test_complete_object_checksum_is_not_read_as_a_body_checksum(s3, bucket, one_part_upload):
    """On Complete, x-amz-checksum-* describes the assembled object."""
    key, upload_id, etag = one_part_upload
    r = s3.request("POST", bucket, key, complete_body([(1, etag)]), query={"uploadId": upload_id},
                   headers={"x-amz-checksum-crc32": crc32_b64(b"only part")})
    assert r.status == 200, r.body


def test_complete_with_too_many_parts(s3, bucket, one_part_upload):
    key, upload_id, etag = one_part_upload
    r = s3.request("POST", bucket, key, complete_body([(n, etag) for n in range(1, 10002)]),
                   query={"uploadId": upload_id})
    assert r.status == 400
    assert r.error_code == "MalformedXML"


# ---- PutObject and UploadPart -------------------------------------------------
#
# Up to 1.7.0 only a literal x-amz-content-sha256 was checked on uploads.
# With UNSIGNED-PAYLOAD a signed checksum header is all that ties the body to
# the request, and a mismatch has to be BadDigest, as on S3.

def sha256_b64(data):
    return base64.b64encode(hashlib.sha256(data).digest()).decode()


UPLOAD_CHECKSUMS = [
    ("x-amz-checksum-crc32", crc32_b64),
    ("x-amz-checksum-sha256", sha256_b64),
    ("x-amz-checksum-crc64nvme", crc64nvme_b64),
    ("Content-MD5", md5_b64),
]
UPLOAD_CHECKSUM_IDS = ["crc32", "sha256", "crc64nvme", "content-md5"]


@pytest.mark.parametrize("header,checksum", UPLOAD_CHECKSUMS, ids=UPLOAD_CHECKSUM_IDS)
def test_put_checks_the_checksum(s3, bucket, prefix, header, checksum):
    # Not a multiple of eight bytes, so the CRC-64 tail is exercised too.
    body = bytes(range(256)) * 41 + b"tail!"
    key = prefix + "obj"

    r = s3.put(bucket, key, b"swapped" + body[7:], payload_hash="UNSIGNED-PAYLOAD", headers={header: checksum(body)})
    assert r.status == 400
    assert r.error_code == "BadDigest"
    assert s3.get(bucket, key).status == 404

    r = s3.put(bucket, key, body, payload_hash="UNSIGNED-PAYLOAD", headers={header: checksum(body)})
    assert r.status == 200, r.body
    assert s3.get(bucket, key).body == body


def test_malformed_content_md5_is_refused(s3, bucket, prefix):
    r = s3.put(bucket, prefix + "obj", b"x", headers={"Content-MD5": "not-base64"})
    assert r.status == 400
    assert r.error_code == "InvalidDigest"


def test_upload_part_checks_the_checksum(s3, boto, bucket, prefix):
    key = prefix + "big.bin"
    upload_id = boto.create_multipart_upload(Bucket=bucket, Key=key)["UploadId"]
    try:
        query = {"partNumber": "1", "uploadId": upload_id}
        r = s3.put(bucket, key, b"tampered", query=query, payload_hash="UNSIGNED-PAYLOAD",
                   headers={"x-amz-checksum-crc32": crc32_b64(b"the part")})
        assert r.status == 400
        assert r.error_code == "BadDigest"
        assert boto.list_parts(Bucket=bucket, Key=key, UploadId=upload_id).get("Parts", []) == []

        r = s3.put(bucket, key, b"the part", query=query, payload_hash="UNSIGNED-PAYLOAD",
                   headers={"x-amz-checksum-crc32": crc32_b64(b"the part")})
        assert r.status == 200, r.body
    finally:
        boto.abort_multipart_upload(Bucket=bucket, Key=key, UploadId=upload_id)


# ---- upload size --------------------------------------------------------------

def test_object_over_5_gib_is_refused_before_the_body_is_read(s3, bucket, prefix):
    """Declared too big, refused before anything reaches the temp directory."""
    r = s3.put_chunked_unsigned(bucket, prefix + "huge", [b"tiny"], decoded=6 * GiB)
    assert r.status == 400
    assert r.error_code == "EntityTooLarge"
    assert s3.get(bucket, prefix + "huge").status == 404


@pytest.fixture
def small_quota(make_user, occ, env):
    nc = make_user()
    nc.mkcol("data")
    bucket = nc.create_bucket("/data", f"q-{nc.user[4:]}")
    key = nc.create_key(bucket["id"])
    # The skeleton files already take some of it; leave one MiB on top.
    body = b'<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:quota-used-bytes/></d:prop></d:propfind>'
    r = nc.http("PROPFIND", f"/remote.php/dav/files/{nc.user}/", body, {"Depth": "0"})
    used = int(ET.fromstring(r.body).findtext(".//{DAV:}quota-used-bytes"))
    occ("user:setting", nc.user, "files", "quota", str(used + 1024 * 1024))
    from s3client import S3Client
    return S3Client(env.endpoint, key["accessKey"], key["secretKey"]), bucket["bucketName"]


def test_quota_bounds_the_body(small_quota):
    """Nextcloud checks the quota when it stores the file, which is after the
    whole body sat in the temp directory. The free space is checked first."""
    client, bucket = small_quota
    assert client.put(bucket, "small", b"x" * 1000).status == 200

    r = client.put(bucket, "big", b"x" * (2 * 1024 * 1024))
    assert r.status == 403
    assert r.error_code == "AccessDenied"
    assert client.get(bucket, "big").status == 404

    r = client.put_chunked_unsigned(bucket, "big", [b"x" * (2 * 1024 * 1024)])
    assert r.status == 403
    assert client.get(bucket, "big").status == 404
