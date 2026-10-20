"""CopyObject."""
import hashlib
import urllib.parse

import pytest
from s3client import NS


def copy(s3, bucket, dest, source):
    return s3.put(bucket, dest, headers={"x-amz-copy-source": source})


def test_copy_object(s3, bucket, prefix):
    s3.put(bucket, prefix + "src", b"payload")
    r = copy(s3, bucket, prefix + "dst/copy", f"/{bucket}/{prefix}src")
    assert r.status == 200
    root = r.xml()
    assert root.tag == f"{NS}CopyObjectResult"
    assert root.findtext(f"{NS}ETag") == f'"{hashlib.md5(b"payload").hexdigest()}"'

    assert s3.get(bucket, prefix + "dst/copy").body == b"payload"
    assert s3.get(bucket, prefix + "src").body == b"payload"


def test_copy_source_forms(s3, bucket, prefix):
    src = prefix + "with space & ümlaut"
    s3.put(bucket, src, b"x")
    encoded = urllib.parse.quote(f"{bucket}/{src}")
    # Without leading slash, percent-encoded, with a versionId suffix.
    r = copy(s3, bucket, prefix + "dst", encoded + "?versionId=null")
    assert r.status == 200, r.body
    assert s3.get(bucket, prefix + "dst").body == b"x"


def test_copy_overwrites(s3, bucket, prefix):
    s3.put(bucket, prefix + "src", b"new")
    s3.put(bucket, prefix + "dst", b"old")
    copy(s3, bucket, prefix + "dst", f"{bucket}/{prefix}src")
    r = s3.get(bucket, prefix + "dst")
    assert r.body == b"new"
    assert r.etag == f'"{hashlib.md5(b"new").hexdigest()}"'


def test_self_copy_keeps_content(s3, bucket, prefix):
    s3.put(bucket, prefix + "obj", b"keep me")
    r = copy(s3, bucket, prefix + "obj", f"{bucket}/{prefix}obj")
    assert r.status == 200
    assert s3.get(bucket, prefix + "obj").body == b"keep me"


@pytest.mark.parametrize("source,status,code", [
    ("{other}/{prefix}src", 400, "InvalidArgument"),
    ("{bucket}", 400, "InvalidArgument"),
    ("{bucket}/{prefix}../escape", 400, "InvalidArgument"),
    ("{bucket}/{prefix}missing", 404, "NoSuchKey"),
    ("{bucket}/{prefix}folder", 404, "NoSuchKey"),
])
def test_copy_errors(s3, env, bucket, prefix, source, status, code):
    s3.put(bucket, prefix + "src", b"x")
    s3.put(bucket, prefix + "folder/child", b"x")
    r = copy(s3, bucket, prefix + "dst", source.format(other=env.other.name, bucket=bucket, prefix=prefix))
    assert r.status == status
    assert r.error_code == code
    assert s3.get(bucket, prefix + "dst").status == 404


def test_copy_via_boto3(boto, s3, bucket, prefix):
    s3.put(bucket, prefix + "src", b"boto")
    boto.copy_object(Bucket=bucket, Key=prefix + "dst", CopySource={"Bucket": bucket, "Key": prefix + "src"})
    assert s3.get(bucket, prefix + "dst").body == b"boto"
