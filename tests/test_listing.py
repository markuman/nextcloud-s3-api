"""ListObjects v1 and v2: prefixes, delimiters, pagination, hidden scratch area."""
import base64
import hashlib
import re

from conftest import put_many
from s3client import NS


def keys_of(root):
    return [c.findtext(f"{NS}Key") for c in root.iter(f"{NS}Contents")]


def prefixes_of(root):
    return [p.findtext(f"{NS}Prefix") for p in root.iter(f"{NS}CommonPrefixes")]


def test_v2_lists_full_keys_sorted(s3, bucket, prefix):
    keys = [prefix + k for k in ("b", "a/2", "a/1", "c/d/e", "a-dash")]
    put_many(s3, bucket, keys, b"12345")

    r = s3.list_v2(bucket, prefix=prefix)
    assert r.status == 200
    assert r.header("content-type").startswith("application/xml")
    root = r.xml()
    # Byte order, as S3: "a-dash" < "a/1" because '-' < '/'.
    assert keys_of(root) == sorted(keys)
    assert root.findtext(f"{NS}KeyCount") == "5"
    assert root.findtext(f"{NS}IsTruncated") == "false"
    assert root.findtext(f"{NS}Prefix") == prefix

    first = next(root.iter(f"{NS}Contents"))
    assert first.findtext(f"{NS}Size") == "5"
    assert first.findtext(f"{NS}ETag") == f'"{hashlib.md5(b"12345").hexdigest()}"'
    assert re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000Z", first.findtext(f"{NS}LastModified"))


def test_folders_are_not_objects(s3, nc, env, bucket, prefix):
    s3.put(bucket, prefix + "dir/file", b"x")
    nc.mkdirs(f"{env.main.folder}/{prefix}empty/nested")
    assert s3.list_all(bucket, prefix) == [prefix + "dir/file"]


def test_prefix_matches_partial_names(s3, bucket, prefix):
    put_many(s3, bucket, [prefix + k for k in ("abc", "abd/x", "b", "ab")])
    assert s3.list_all(bucket, prefix + "ab") == [prefix + "ab", prefix + "abc", prefix + "abd/x"]
    assert s3.list_all(bucket, prefix + "abd/") == [prefix + "abd/x"]
    assert s3.list_all(bucket, prefix + "zzz") == []
    assert s3.list_all(bucket, prefix + "missing/dir/") == []


def test_prefix_without_slash_starts_at_bucket_root(s3, bucket, prefix):
    s3.put(bucket, prefix + "x", b"x")
    head = prefix.rstrip("/")[:-2]  # cut into the top level folder name
    assert prefix + "x" in s3.list_all(bucket, head)


def test_empty_prefix_lists_whole_bucket(s3, bucket, prefix):
    s3.put(bucket, prefix + "x", b"x")
    assert prefix + "x" in s3.list_all(bucket)


def test_delimiter(s3, bucket, prefix):
    put_many(s3, bucket, [prefix + k for k in ("top", "a/1", "a/2", "b/c/d", "bb")])

    root = s3.list_v2(bucket, prefix=prefix, delimiter="/").xml()
    assert keys_of(root) == [prefix + "bb", prefix + "top"]
    assert prefixes_of(root) == [prefix + "a/", prefix + "b/"]
    assert root.findtext(f"{NS}KeyCount") == "4"

    root = s3.list_v2(bucket, prefix=prefix + "b", delimiter="/").xml()
    assert keys_of(root) == [prefix + "bb"]
    assert prefixes_of(root) == [prefix + "b/"]


def test_pagination_v2(s3, bucket, prefix):
    keys = sorted(prefix + f"k{i}" for i in range(7))
    put_many(s3, bucket, keys)

    seen, token, pages = [], None, 0
    while True:
        params = {"prefix": prefix, "max_keys": 3}
        if token:
            params["continuation_token"] = token
        root = s3.list_v2(bucket, **params).xml()
        pages += 1
        page = keys_of(root)
        assert len(page) <= 3
        seen += page
        if root.findtext(f"{NS}IsTruncated") != "true":
            assert root.find(f"{NS}NextContinuationToken") is None
            break
        token = root.findtext(f"{NS}NextContinuationToken")
        assert token
        if pages > 1:
            assert root.findtext(f"{NS}ContinuationToken") == params["continuation_token"]
    assert seen == keys
    assert pages == 3


def test_default_page_is_1000_and_continues(s3, bucket, many_keys):
    p, keys = many_keys
    root = s3.list_v2(bucket, prefix=p).xml()
    assert len(keys_of(root)) == 1000
    assert root.findtext(f"{NS}MaxKeys") == "1000"
    assert root.findtext(f"{NS}IsTruncated") == "true"

    token = root.findtext(f"{NS}NextContinuationToken")
    assert base64.b64decode(token).decode() == keys[999]
    rest = keys_of(s3.list_v2(bucket, prefix=p, continuation_token=token).xml())
    assert rest == keys[1000:]


def test_max_keys_is_capped(s3, bucket, many_keys):
    p, _ = many_keys
    root = s3.list_v2(bucket, prefix=p, max_keys=5000).xml()
    assert root.findtext(f"{NS}MaxKeys") == "1000"
    assert len(keys_of(root)) == 1000


def test_start_after(s3, bucket, prefix):
    keys = sorted(prefix + f"k{i}.pack" for i in range(5))
    put_many(s3, bucket, keys)
    # The dots matter: parse_str() would have mangled the parameter.
    root = s3.list_v2(bucket, prefix=prefix, start_after=keys[1]).xml()
    assert keys_of(root) == keys[2:]
    assert root.findtext(f"{NS}StartAfter") == keys[1]


def test_v1_marker_pagination(s3, bucket, prefix):
    keys = sorted(prefix + f"k{i}" for i in range(5))
    put_many(s3, bucket, keys)

    root = s3.request("GET", bucket, query={"prefix": prefix, "max-keys": "2"}).xml()
    assert keys_of(root) == keys[:2]
    assert root.findtext(f"{NS}IsTruncated") == "true"
    assert root.findtext(f"{NS}NextMarker") == keys[1]

    root = s3.request("GET", bucket, query={"prefix": prefix, "marker": keys[1]}).xml()
    assert keys_of(root) == keys[2:]
    assert root.findtext(f"{NS}IsTruncated") == "false"


def test_v1_delimiter(s3, bucket, prefix):
    put_many(s3, bucket, [prefix + k for k in ("x", "d/y")])
    root = s3.request("GET", bucket, query={"prefix": prefix, "delimiter": "/"}).xml()
    assert keys_of(root) == [prefix + "x"]
    assert prefixes_of(root) == [prefix + "d/"]


def test_multipart_scratch_area_is_hidden(s3, boto, bucket, prefix):
    upload = boto.create_multipart_upload(Bucket=bucket, Key=prefix + "big")
    boto.upload_part(Bucket=bucket, Key=prefix + "big", UploadId=upload["UploadId"], PartNumber=1, Body=b"x")
    try:
        everything = s3.list_all(bucket)
        assert not any(k.startswith(".s3-uploads") for k in everything)
        root = s3.list_v2(bucket, delimiter="/").xml()
        assert ".s3-uploads/" not in prefixes_of(root)
    finally:
        boto.abort_multipart_upload(Bucket=bucket, Key=prefix + "big", UploadId=upload["UploadId"])


def test_boto3_paginator(boto, bucket, many_keys):
    p, keys = many_keys
    pages = boto.get_paginator("list_objects_v2").paginate(Bucket=bucket, Prefix=p)
    assert [o["Key"] for page in pages for o in page.get("Contents", [])] == keys


def test_prefix_cannot_leave_or_alias_the_bucket(s3, s3_ro, boto, env, bucket, prefix):
    """The folder part of a prefix is resolved as a path. A segment no key can
    have (`..`, `.`, empty, a backslash) must not reach another folder -- not
    the owner's home, not the bucket root under a second name, not the
    multipart scratch area -- and the scratch area is not listable by name."""
    put_many(s3, bucket, [prefix + "a/x", prefix + "b"])
    upload = boto.create_multipart_upload(Bucket=bucket, Key=prefix + "big")
    boto.upload_part(Bucket=bucket, Key=prefix + "big", UploadId=upload["UploadId"], PartNumber=1, Body=b"x")
    try:
        for p in ("../", "../" + env.other.folder + "/", prefix + "a/../", prefix + "a/../../",
                  "./", "./.s3-uploads/", prefix + "./", prefix + "a//", "/" + prefix, prefix + "a\\",
                  ".s3-uploads/", ".s3-uploads/" + upload["UploadId"] + "/"):
            for client in (s3, s3_ro):
                r = client.list_v2(bucket, prefix=p)
                assert r.status == 200, (p, r.status, r.body)
                root = r.xml()
                assert keys_of(root) == [], p
                assert prefixes_of(root) == [], p
                r = client.list_v2(bucket, prefix=p, delimiter="/")
                assert r.status == 200, (p, r.status, r.body)
                assert keys_of(r.xml()) == [] and prefixes_of(r.xml()) == [], p
        # The plain spelling still works.
        assert s3.list_all(bucket, prefix + "a/") == [prefix + "a/x"]
    finally:
        boto.abort_multipart_upload(Bucket=bucket, Key=prefix + "big", UploadId=upload["UploadId"])


def test_nfd_prefix_matches_nothing(s3, bucket, prefix):
    """Stored names are NFC; an NFD folder in the prefix would otherwise be
    resolved to the NFC folder and list its keys under names that 400."""
    put_many(s3, bucket, [prefix + "caf\u00e9/x"])
    assert s3.list_all(bucket, prefix + "cafe\u0301/") == []
    assert s3.list_all(bucket, prefix + "caf\u00e9/") == [prefix + "caf\u00e9/x"]
