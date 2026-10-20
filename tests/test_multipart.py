"""Multipart uploads, UploadPartCopy and the scratch area."""
import hashlib
import os

import pytest
from botocore.exceptions import ClientError
from s3client import NS

MiB = 1024 * 1024


def compound_etag(parts):
    digest = hashlib.md5(b"".join(hashlib.md5(p).digest() for p in parts)).hexdigest()
    return f'"{digest}-{len(parts)}"'


def error_code(excinfo):
    return excinfo.value.response["Error"]["Code"]


@pytest.fixture
def upload(boto, bucket, prefix):
    """Start an upload; abort it afterwards unless the test completed it."""
    key = prefix + "big.bin"
    upload_id = boto.create_multipart_upload(Bucket=bucket, Key=key)["UploadId"]
    yield key, upload_id
    try:
        boto.abort_multipart_upload(Bucket=bucket, Key=key, UploadId=upload_id)
    except ClientError:
        pass


def put_part(boto, bucket, key, upload_id, number, data):
    return boto.upload_part(Bucket=bucket, Key=key, UploadId=upload_id, PartNumber=number, Body=data)["ETag"]


def complete(boto, bucket, key, upload_id, parts):
    return boto.complete_multipart_upload(
        Bucket=bucket, Key=key, UploadId=upload_id,
        MultipartUpload={"Parts": [{"PartNumber": n, "ETag": e} for n, e in parts]},
    )


def test_complete_upload(boto, s3, bucket, upload):
    key, upload_id = upload
    parts = [os.urandom(5 * MiB), b"tail"]
    etags = [put_part(boto, bucket, key, upload_id, i + 1, p) for i, p in enumerate(parts)]
    assert etags == [f'"{hashlib.md5(p).hexdigest()}"' for p in parts]

    out = complete(boto, bucket, key, upload_id, list(zip([1, 2], etags)))
    assert out["ETag"] == compound_etag(parts)

    r = s3.get(bucket, key)
    assert r.body == b"".join(parts)
    # The compound ETag is recorded, not replaced by a content hash on read.
    assert r.etag == compound_etag(parts)
    assert s3.head(bucket, key).etag == compound_etag(parts)

    uploads = boto.list_multipart_uploads(Bucket=bucket).get("Uploads", [])
    assert upload_id not in [u["UploadId"] for u in uploads]


def test_list_parts_and_uploads(boto, bucket, upload):
    key, upload_id = upload
    put_part(boto, bucket, key, upload_id, 2, b"two")
    put_part(boto, bucket, key, upload_id, 1, b"one!")

    parts = boto.list_parts(Bucket=bucket, Key=key, UploadId=upload_id)["Parts"]
    assert [(p["PartNumber"], p["Size"]) for p in parts] == [(1, 4), (2, 3)]
    assert parts[0]["ETag"] == f'"{hashlib.md5(b"one!").hexdigest()}"'

    uploads = boto.list_multipart_uploads(Bucket=bucket)["Uploads"]
    assert (key, upload_id) in [(u["Key"], u["UploadId"]) for u in uploads]


def test_reuploading_a_part_replaces_it(boto, s3, bucket, upload):
    key, upload_id = upload
    put_part(boto, bucket, key, upload_id, 1, b"first")
    etag = put_part(boto, bucket, key, upload_id, 1, b"second")
    complete(boto, bucket, key, upload_id, [(1, etag)])
    assert s3.get(bucket, key).body == b"second"


def test_abort(boto, s3, bucket, upload):
    key, upload_id = upload
    put_part(boto, bucket, key, upload_id, 1, b"x")
    boto.abort_multipart_upload(Bucket=bucket, Key=key, UploadId=upload_id)

    with pytest.raises(ClientError) as e:
        boto.list_parts(Bucket=bucket, Key=key, UploadId=upload_id)
    assert error_code(e) == "NoSuchUpload"
    assert s3.get(bucket, key).status == 404


def test_part_below_minimum_size(boto, bucket, upload):
    key, upload_id = upload
    e1 = put_part(boto, bucket, key, upload_id, 1, b"small")
    e2 = put_part(boto, bucket, key, upload_id, 2, b"last")
    with pytest.raises(ClientError) as e:
        complete(boto, bucket, key, upload_id, [(1, e1), (2, e2)])
    assert error_code(e) == "EntityTooSmall"


def test_parts_out_of_order(boto, bucket, upload):
    key, upload_id = upload
    e1 = put_part(boto, bucket, key, upload_id, 1, b"a")
    e2 = put_part(boto, bucket, key, upload_id, 2, b"b")
    with pytest.raises(ClientError) as e:
        complete(boto, bucket, key, upload_id, [(2, e2), (1, e1)])
    assert error_code(e) == "InvalidPartOrder"


@pytest.mark.parametrize("parts", [[(1, '"0000"')], [(2, None)]], ids=["wrong-etag", "missing-part"])
def test_invalid_part(boto, bucket, upload, parts):
    key, upload_id = upload
    etag = put_part(boto, bucket, key, upload_id, 1, b"a")
    parts = [(n, e or etag) for n, e in parts]
    with pytest.raises(ClientError) as e:
        complete(boto, bucket, key, upload_id, parts)
    assert error_code(e) == "InvalidPart"


def test_upload_id_is_bound_to_its_key(boto, bucket, prefix, upload):
    _, upload_id = upload
    with pytest.raises(ClientError) as e:
        boto.upload_part(Bucket=bucket, Key=prefix + "other", UploadId=upload_id, PartNumber=1, Body=b"x")
    assert error_code(e) == "NoSuchUpload"


@pytest.mark.parametrize("upload_id", ["0" * 32, "../../etc", "NOTHEX" * 6])
def test_unknown_upload_id(s3, bucket, prefix, upload_id):
    r = s3.put(bucket, prefix + "x", b"x", query={"uploadId": upload_id, "partNumber": "1"})
    assert r.status == 404
    assert r.error_code == "NoSuchUpload"


@pytest.mark.parametrize("number,status", [("0", 400), ("10001", 400), ("abc", 400)])
def test_part_number_bounds(s3, bucket, upload, number, status):
    key, upload_id = upload
    r = s3.put(bucket, key, b"x", query={"uploadId": upload_id, "partNumber": number})
    assert r.status == status
    assert r.error_code == "InvalidArgument"


def test_upload_part_without_number(s3, bucket, upload):
    key, upload_id = upload
    r = s3.put(bucket, key, b"x", query={"uploadId": upload_id})
    assert r.status == 400
    assert r.error_code == "InvalidRequest"


def test_complete_with_malformed_body(s3, bucket, upload):
    key, upload_id = upload
    for body in (b"", b"<CompleteMultipartUpload><Part>", b"<CompleteMultipartUpload/>"):
        r = s3.request("POST", bucket, key, body, query={"uploadId": upload_id})
        assert r.status == 400, body
        assert r.error_code == "MalformedXML"


def test_initiate_with_valueless_uploads(s3, boto, bucket, prefix):
    """`?uploads` without `=`, as the SDKs send it."""
    r = s3.request("POST", bucket, prefix + "x", query={"uploads": ""}, raw_query="uploads")
    assert r.status == 200
    root = r.xml()
    assert root.findtext(f"{NS}Key") == prefix + "x"
    upload_id = root.findtext(f"{NS}UploadId")
    boto.abort_multipart_upload(Bucket=bucket, Key=prefix + "x", UploadId=upload_id)


def test_upload_part_copy_composes_objects(boto, s3, bucket, prefix, upload):
    key, upload_id = upload
    first = os.urandom(5 * MiB)
    s3.put(bucket, prefix + "first", first)
    s3.put(bucket, prefix + "second", b"0123456789")

    e1 = boto.upload_part_copy(Bucket=bucket, Key=key, UploadId=upload_id, PartNumber=1,
                               CopySource={"Bucket": bucket, "Key": prefix + "first"})["CopyPartResult"]["ETag"]
    e2 = boto.upload_part_copy(Bucket=bucket, Key=key, UploadId=upload_id, PartNumber=2,
                               CopySource={"Bucket": bucket, "Key": prefix + "second"},
                               CopySourceRange="bytes=2-5")["CopyPartResult"]["ETag"]
    complete(boto, bucket, key, upload_id, [(1, e1), (2, e2)])

    assert s3.get(bucket, key).body == first + b"2345"


@pytest.mark.parametrize("spec", ["bytes=5-2", "bytes=0-10", "bytes=3-", "items=0-1"])
def test_upload_part_copy_bad_range(s3, bucket, prefix, upload, spec):
    key, upload_id = upload
    s3.put(bucket, prefix + "src", b"0123456789")
    r = s3.put(bucket, key, query={"uploadId": upload_id, "partNumber": "1"},
               headers={"x-amz-copy-source": f"{bucket}/{prefix}src", "x-amz-copy-source-range": spec})
    assert r.status == 400
    assert r.error_code == "InvalidArgument"


def test_upload_file_with_multipart(boto_factory, s3, bucket, prefix, tmp_path):
    """The high level transfer path, the way backup tools use it."""
    from boto3.s3.transfer import TransferConfig

    data = os.urandom(11 * MiB)
    path = tmp_path / "blob"
    path.write_bytes(data)
    cfg = TransferConfig(multipart_threshold=5 * MiB, multipart_chunksize=5 * MiB, max_concurrency=3)
    boto_factory().upload_file(str(path), bucket, prefix + "blob", Config=cfg)

    r = s3.get(bucket, prefix + "blob")
    assert r.body == data
    assert r.etag.endswith('-3"')
