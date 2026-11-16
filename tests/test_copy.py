"""CopyObject."""
import email.utils
import hashlib
import time
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
    # Source keys get the same validation as keys in the request line: these
    # would otherwise resolve to {prefix}src or into the scratch area.
    ("{bucket}/{prefix}/src", 400, "InvalidArgument"),
    ("{bucket}/{prefix}./src", 400, "InvalidArgument"),
    ("{bucket}/.s3-uploads/x", 400, "InvalidArgument"),
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


def test_copy_source_with_dots_in_a_name(s3, bucket, prefix):
    """`..` is only refused as a whole segment; up to 1.5.4 any source key
    containing it was, although `a..b` is a valid key."""
    s3.put(bucket, prefix + "a..b", b"dots")
    r = copy(s3, bucket, prefix + "dst", f"{bucket}/{prefix}a..b")
    assert r.status == 200, r.body
    assert s3.get(bucket, prefix + "dst").body == b"dots"


def test_copy_via_boto3(boto, s3, bucket, prefix):
    s3.put(bucket, prefix + "src", b"boto")
    boto.copy_object(Bucket=bucket, Key=prefix + "dst", CopySource={"Bucket": bucket, "Key": prefix + "src"})
    assert s3.get(bucket, prefix + "dst").body == b"boto"


def http_date(t):
    return email.utils.formatdate(t, usegmt=True)


@pytest.mark.parametrize("header,value,copies", [
    ("x-amz-copy-source-if-match", "{etag}", True),
    ("x-amz-copy-source-if-match", '"0123"', False),
    ("x-amz-copy-source-if-none-match", "{etag}", False),
    ("x-amz-copy-source-if-none-match", '"0123"', True),
    ("x-amz-copy-source-if-modified-since", "{future}", False),
    ("x-amz-copy-source-if-modified-since", "{past}", True),
    ("x-amz-copy-source-if-unmodified-since", "{past}", False),
    ("x-amz-copy-source-if-unmodified-since", "{future}", True),
])
def test_copy_source_conditions(s3, bucket, prefix, header, value, copies):
    """Up to 1.7.2 these were ignored: a copy meant for one version of the
    source copied whatever was there."""
    etag = s3.put(bucket, prefix + "src", b"payload").etag
    value = value.format(etag=etag, future=http_date(time.time() + 3600), past=http_date(946684800))
    r = s3.put(bucket, prefix + "dst", headers={"x-amz-copy-source": f"{bucket}/{prefix}src", header: value})
    if copies:
        assert r.status == 200, r.body
        assert s3.get(bucket, prefix + "dst").body == b"payload"
    else:
        assert r.status == 412
        assert r.error_code == "PreconditionFailed"
        assert s3.get(bucket, prefix + "dst").status == 404


def test_copy_honours_conditions_on_the_target(s3, bucket, prefix):
    """If-Match / If-None-Match on the target, as on PutObject. Up to 1.7.2
    ignored: a compare-and-swap client got 200 for an update it lost."""
    s3.put(bucket, prefix + "src", b"new")
    old = s3.put(bucket, prefix + "dst", b"old").etag
    source = {"x-amz-copy-source": f"{bucket}/{prefix}src"}

    for condition in ({"If-None-Match": "*"}, {"If-Match": '"0123"'}, {"If-None-Match": old}):
        r = s3.put(bucket, prefix + "dst", headers={**source, **condition})
        assert r.status == 412, condition
        assert r.error_code == "PreconditionFailed"
        assert s3.get(bucket, prefix + "dst").body == b"old"

    assert s3.put(bucket, prefix + "dst", headers={**source, "If-Match": old}).status == 200
    assert s3.get(bucket, prefix + "dst").body == b"new"
    assert s3.put(bucket, prefix + "fresh", headers={**source, "If-None-Match": "*"}).status == 200
    assert s3.get(bucket, prefix + "fresh").body == b"new"


def test_self_copy_with_if_none_match_fails(s3, bucket, prefix):
    s3.put(bucket, prefix + "obj", b"keep")
    r = s3.put(bucket, prefix + "obj", headers={"x-amz-copy-source": f"{bucket}/{prefix}obj", "If-None-Match": "*"})
    assert r.status == 412
