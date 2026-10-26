"""aws-chunked request bodies, signed and unsigned."""
import base64
import os
import zlib

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


def crc32_b64(data):
    return base64.b64encode(zlib.crc32(data).to_bytes(4, "big")).decode()


@pytest.mark.parametrize("trailer", [None, ("x-amz-checksum-crc32", crc32_b64(b"hello world"))],
                         ids=["no-trailer", "crc32"])
def test_unsigned_trailer_framing(s3, bucket, prefix, trailer):
    chunks = [b"hello ", b"world"]
    r = s3.put_chunked_unsigned(bucket, prefix + "obj", chunks, trailer=trailer)
    assert r.status == 200, r.body
    assert s3.get(bucket, prefix + "obj").body == b"hello world"


def test_trailer_checksum_is_checked(s3, bucket, prefix):
    """The checksum in the trailer describes the decoded bytes; one that does
    not match them is BadDigest, as on S3, and nothing is stored."""
    r = s3.put_chunked_unsigned(bucket, prefix + "obj", [b"hello ", b"world"],
                                trailer=("x-amz-checksum-crc32", crc32_b64(b"hello there")))
    assert r.status == 400
    assert r.error_code == "BadDigest"
    assert s3.get(bucket, prefix + "obj").status == 404


@pytest.mark.parametrize("final", [True, False], ids=["trailer-missing", "cut-short"])
def test_announced_trailer_must_arrive(s3, bucket, prefix, final):
    """`x-amz-trailer` is signed: the client committed to a checksum. Without
    it, whoever strips the trailer and swaps the body would pass unchecked."""
    r = s3.put_chunked_unsigned(bucket, prefix + "obj", [b"hello ", b"world"], final=final,
                                headers={"x-amz-trailer": "x-amz-checksum-crc32"})
    assert r.status == 400
    assert r.error_code == "IncompleteBody"
    assert s3.get(bucket, prefix + "obj").status == 404


@pytest.mark.parametrize("checksum,status", [(b"hello world", 200), (b"hello there", 400)],
                         ids=["matching", "mismatching"])
def test_signed_chunks_with_trailer(s3, bucket, prefix, checksum, status):
    """`STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER`: the checksum is checked
    against the bytes, the `x-amz-trailer-signature` line is passed over."""
    r = s3.put_chunked_signed(bucket, prefix + "obj", [b"hello ", b"world"],
                              trailer=("x-amz-checksum-crc32", crc32_b64(checksum)))
    assert r.status == status, r.body
    if status == 200:
        assert s3.get(bucket, prefix + "obj").body == b"hello world"
    else:
        assert r.error_code == "BadDigest"
        assert s3.get(bucket, prefix + "obj").status == 404


def test_unannounced_trailer_checksum_is_refused(s3, bucket, prefix):
    r = s3.put_chunked_unsigned(bucket, prefix + "obj", [b"hello ", b"world"],
                                trailer=("x-amz-checksum-crc32", crc32_b64(b"hello world")), announce=False)
    assert r.status == 400
    assert r.error_code == "InvalidRequest"
    assert s3.get(bucket, prefix + "obj").status == 404


@pytest.mark.parametrize("declared,final", [(5, True), (500, True), (500, False)],
                         ids=["shorter", "longer", "cut-short"])
def test_decoded_length_is_enforced(s3, bucket, prefix, declared, final):
    """`x-amz-decoded-content-length` is the object's size; a body that does
    not match it -- including one cut off before its final chunk, which an
    unsigned body may otherwise omit -- is incomplete."""
    r = s3.put_chunked_unsigned(bucket, prefix + "obj", [b"hello ", b"world"], decoded=declared, final=final)
    assert r.status == 400
    assert r.error_code == "IncompleteBody"
    assert s3.get(bucket, prefix + "obj").status == 404


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
