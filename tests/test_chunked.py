"""aws-chunked request bodies, signed and unsigned."""
import os

import pytest


def test_signed_chunks_round_trip(s3, bucket, prefix):
    chunks = [os.urandom(64 * 1024), os.urandom(64 * 1024), b"tail"]
    r = s3.put_chunked_signed(bucket, prefix + "obj", chunks)
    assert r.status == 200, r.body
    # The framing must be decoded, not stored.
    assert s3.get(bucket, prefix + "obj").body == b"".join(chunks)


def test_tampered_chunk_is_rejected(s3, bucket, prefix):
    chunks = [b"A" * 1000, b"B" * 1000]
    r = s3.put_chunked_signed(bucket, prefix + "obj", chunks,
                              tamper=lambda i, d: b"C" * len(d) if i == 1 else d)
    assert r.status == 403
    assert r.error_code == "SignatureDoesNotMatch"
    assert s3.get(bucket, prefix + "obj").status == 404


def test_signed_body_without_final_chunk(s3, bucket, prefix):
    r = s3.put_chunked_signed(bucket, prefix + "obj", [b"data"], drop_final=True)
    assert r.status == 400
    assert r.error_code == "IncompleteBody"
    assert s3.get(bucket, prefix + "obj").status == 404


def test_signed_chunks_for_conditional_write(s3, bucket, prefix):
    key = prefix + "obj"
    r = s3.put_chunked_signed(bucket, key, [b"one"], headers={"If-None-Match": "*"})
    assert r.status == 200
    r = s3.put_chunked_signed(bucket, key, [b"two"], headers={"If-None-Match": "*"})
    assert r.status == 412
    assert s3.get(bucket, key).body == b"one"


@pytest.mark.parametrize("trailer", [None, ("x-amz-checksum-crc32", "AAAAAA==")])
def test_unsigned_trailer_framing(s3, bucket, prefix, trailer):
    chunks = [b"hello ", b"world"]
    r = s3.put_chunked_unsigned(bucket, prefix + "obj", chunks, trailer=trailer)
    assert r.status == 200, r.body
    assert s3.get(bucket, prefix + "obj").body == b"hello world"


def test_malformed_chunk_header(s3, bucket, prefix):
    body = b"zz\r\nabc\r\n0\r\n\r\n"
    r = s3.request("PUT", bucket, prefix + "obj", body, payload_hash="STREAMING-UNSIGNED-PAYLOAD-TRAILER",
                   headers={"Content-Encoding": "aws-chunked"})
    assert r.status == 400
    assert r.error_code == "InvalidRequest"


@pytest.mark.parametrize("checksums", ["when_supported", "when_required"])
def test_boto3_put_object(boto_factory, s3, bucket, prefix, checksums):
    """Whatever framing the installed botocore picks for a plain PutObject."""
    client = boto_factory(request_checksum_calculation=checksums)
    data = os.urandom(100_000)
    client.put_object(Bucket=bucket, Key=prefix + "obj", Body=data)
    assert s3.get(bucket, prefix + "obj").body == data
