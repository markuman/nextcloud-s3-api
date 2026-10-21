"""PutObject, GetObject, HeadObject: bytes, ETags, keys, ranges, conditional reads."""
import hashlib
import os
import re

import pytest


def md5(data):
    return hashlib.md5(data).hexdigest()


# ---- round trips ------------------------------------------------------------

@pytest.mark.parametrize("body", [b"hello world\n", b"", os.urandom(300_000)], ids=["text", "empty", "binary"])
def test_put_get_round_trip(s3, bucket, prefix, body):
    key = prefix + "obj"
    r = s3.put(bucket, key, body)
    # gits3 accepts exactly 200 for a PUT, nothing else.
    assert r.status == 200
    assert r.etag == f'"{md5(body)}"'

    r = s3.get(bucket, key)
    assert r.status == 200
    assert r.body == body
    assert r.etag == f'"{md5(body)}"'
    assert r.header("content-length") == str(len(body))


def test_head_reports_size_without_body(s3, bucket, prefix):
    body = b"x" * 12345
    s3.put(bucket, prefix + "obj", body)

    r = s3.head(bucket, prefix + "obj")
    assert r.status == 200
    assert r.body == b""
    # The framework would rewrite this to 0 for an empty body.
    assert r.header("content-length") == "12345"
    assert r.etag == f'"{md5(body)}"'
    assert r.header("accept-ranges") == "bytes"
    assert re.fullmatch(r"\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT", r.header("last-modified"))


def test_missing_key(s3, bucket, prefix):
    r = s3.get(bucket, prefix + "nope")
    assert r.status == 404
    assert r.error_code == "NoSuchKey"

    r = s3.head(bucket, prefix + "nope")
    assert r.status == 404
    assert r.body == b""


def test_overwrite_changes_etag(s3, bucket, prefix):
    key = prefix + "obj"
    first = s3.put(bucket, key, b"one").etag
    second = s3.put(bucket, key, b"two").etag
    assert first != second
    assert s3.get(bucket, key).body == b"two"


def test_same_size_different_content_in_same_second(s3, bucket, prefix):
    """Nextcloud's own etag would collide here (mtime has 1 s resolution)."""
    key = prefix + "obj"
    etags = {s3.put(bucket, key, bytes([65 + i]) * 64).etag for i in range(5)}
    assert len(etags) == 5


def test_identical_rewrite_keeps_etag(s3, bucket, prefix):
    """Content-derived ETags, as on AWS: same bytes, same ETag.

    Which is why gits3's gc cannot fence off concurrent pushers by rewriting
    packed-refs unchanged; it changes the header line instead.
    """
    key = prefix + "packed-refs"
    first = s3.put(bucket, key, b"same").etag
    second = s3.put(bucket, key, b"same", headers={"If-Match": first}).etag
    assert first == second


# ---- keys -------------------------------------------------------------------

@pytest.mark.parametrize("name", [
    "deep/er/still/deeper/obj.bin",
    "with space.txt",
    "ümläut-€.txt",
    "plus+equals=amp&.txt",
    "percent%20literal.txt",
    "quote'paren(1).txt",
    ".dotfile",
    "repo.git/refs/.keep",
    "a..b",
])
def test_key_names_round_trip(s3, bucket, prefix, name):
    key = prefix + name
    r = s3.put(bucket, key, name.encode())
    assert r.status == 200, r.body
    r = s3.get(bucket, key)
    assert r.status == 200
    assert r.body == name.encode()
    assert key in s3.list_all(bucket, prefix)


def test_nested_key_is_a_nextcloud_file(s3, nc, env, bucket, prefix):
    s3.put(bucket, prefix + "a/b/c.txt", b"via s3")
    r = nc.download(f"{env.main.folder}/{prefix}a/b/c.txt")
    assert r.status == 200
    assert r.body == b"via s3"


@pytest.mark.parametrize("suffix", [
    "dir/",
    "a//b",
    "a/./b",
    "a/../b",
    ".s3-uploads/x",
])
def test_unusable_keys_are_rejected(s3, bucket, prefix, suffix):
    key = (prefix + suffix) if not suffix.startswith(".s3") else suffix
    r = s3.put(bucket, key, b"x")
    assert r.status == 400
    assert r.error_code == "InvalidArgument"


def test_backslash_is_not_a_second_separator(s3, bucket, prefix):
    """Nextcloud reads `\\` as `/`, so `a\\b` would alias `a/b` under a lock
    of its own."""
    s3.put(bucket, prefix + "a/b", b"keep")
    r = s3.put(bucket, prefix + "a\\b", b"overwrite")
    assert r.status == 400
    assert r.error_code == "InvalidArgument"
    assert s3.get(bucket, prefix + "a/b").body == b"keep"


def test_key_length_limit(s3, bucket, prefix):
    """1024 bytes, as on S3."""
    r = s3.put(bucket, prefix + "/".join(["d" * 100] * 10) + "/" + "x" * 50, b"x")
    assert r.status == 400
    assert r.error_code == "KeyTooLongError"


@pytest.mark.parametrize("name,status,code", [
    ("n" * 300, 400, "InvalidArgument"),
    (".htaccess", 403, "AccessDenied"),
], ids=["long-segment", "reserved-name"])
def test_names_nextcloud_refuses_are_client_errors(s3, bucket, prefix, name, status, code):
    """Names Nextcloud will not store are the client's problem, not a 500."""
    r = s3.put(bucket, prefix + name, b"x")
    assert r.status == status, r.body
    assert r.error_code == code


def test_key_with_leading_slash_is_rejected(s3, bucket, prefix):
    """`bucket//x` addresses the key `/x`, which has an empty first segment."""
    s3.put(bucket, prefix + "x", b"keep")
    r = s3.put(bucket, "/" + prefix + "x", b"overwrite")
    assert r.status == 400
    assert r.error_code == "InvalidArgument"
    assert s3.get(bucket, prefix + "x").body == b"keep"


@pytest.mark.parametrize("method", ["GET", "HEAD", "PUT", "DELETE"])
def test_empty_segment_does_not_alias(s3, bucket, prefix, method):
    """Regression: Nextcloud collapses `//` before routing, so up to 1.5.4 a
    request for `a//b` was served from `a/b` -- a DELETE removed it."""
    s3.put(bucket, prefix + "a/b", b"keep")
    r = s3.request(method, bucket, prefix + "a//b", b"overwrite" if method == "PUT" else b"")
    assert r.status == 400
    if method != "HEAD":
        assert r.error_code == "InvalidArgument"
    assert s3.get(bucket, prefix + "a/b").body == b"keep"


def test_doubled_slash_before_the_bucket_is_tolerated(env, bucket, prefix):
    """An endpoint configured with a trailing slash makes some clients send
    `…/s3//bucket/key`. That names no key, so it keeps working."""
    client = env.client(env.rw)
    client.base += "/"
    assert client.uri(bucket, prefix + "x").count("//") == 1
    assert client.put(bucket, prefix + "x", b"via //").status == 200
    assert client.get(bucket, prefix + "x").body == b"via //"
    assert client.list_all(bucket, prefix) == [prefix + "x"]


def test_put_below_a_file_conflicts(s3, bucket, prefix):
    s3.put(bucket, prefix + "a", b"file")
    r = s3.put(bucket, prefix + "a/b", b"x")
    assert r.status == 409
    assert s3.get(bucket, prefix + "a").body == b"file"


def test_put_onto_a_folder_conflicts(s3, bucket, prefix):
    s3.put(bucket, prefix + "dir/child", b"x")
    r = s3.put(bucket, prefix + "dir", b"x")
    assert r.status == 409
    assert s3.get(bucket, prefix + "dir/child").body == b"x"


def test_get_on_a_folder_is_no_such_key(s3, bucket, prefix):
    s3.put(bucket, prefix + "dir/child", b"x")
    r = s3.get(bucket, prefix + "dir")
    assert r.status == 404
    assert r.error_code == "NoSuchKey"
    assert s3.head(bucket, prefix + "dir").status == 404


@pytest.mark.parametrize("name,attachment", [("page.html", True), ("pic.svg", True), ("notes.txt", False)])
def test_active_content_is_served_as_download(s3, bucket, prefix, name, attachment):
    """A presigned URL opens on the Nextcloud origin; HTML or SVG rendered
    there could pose as a login page."""
    s3.put(bucket, prefix + name, b"<html><form></form></html>")
    r = s3.get(bucket, prefix + name)
    assert r.header("x-content-type-options") == "nosniff"
    assert (r.header("content-disposition") == "attachment") is attachment


# ---- files written outside the API ------------------------------------------

def test_file_from_webdav_gets_content_etag(s3, nc, env, bucket, prefix):
    nc.mkdirs(f"{env.main.folder}/{prefix}")
    nc.upload(f"{env.main.folder}/{prefix}marker", b"from the web ui")

    r = s3.head(bucket, prefix + "marker")
    assert r.status == 200
    assert r.etag == f'"{md5(b"from the web ui")}"'


def test_webdav_change_is_noticed(s3, nc, env, bucket, prefix):
    key = prefix + "obj"
    s3.put(bucket, key, b"via s3")
    nc.upload(f"{env.main.folder}/{key}", b"changed behind the API's back")

    r = s3.get(bucket, key)
    assert r.body == b"changed behind the API's back"
    assert r.etag == f'"{md5(r.body)}"'


# ---- ranges -----------------------------------------------------------------

BODY = bytes(range(256)) * 4  # 1024 bytes


@pytest.fixture
def ranged(s3, bucket, prefix):
    s3.put(bucket, prefix + "r", BODY)
    return lambda spec: s3.get(bucket, prefix + "r", headers={"Range": spec})


@pytest.mark.parametrize("spec,start,end", [
    ("bytes=0-9", 0, 9),
    ("bytes=1000-", 1000, 1023),
    ("bytes=-24", 1000, 1023),
    ("bytes=1020-5000", 1020, 1023),
    ("bytes=-5000", 0, 1023),
])
def test_range(ranged, spec, start, end):
    r = ranged(spec)
    assert r.status == 206
    assert r.body == BODY[start:end + 1]
    assert r.header("content-range") == f"bytes {start}-{end}/1024"
    assert r.header("content-length") == str(end - start + 1)


@pytest.mark.parametrize("spec", ["bytes=1024-", "bytes=5-2", "bytes=-0"])
def test_unsatisfiable_range(ranged, spec):
    r = ranged(spec)
    assert r.status == 416
    assert r.error_code == "InvalidRange"
    assert r.header("content-range") == "bytes */1024"


@pytest.mark.parametrize("spec", ["bytes=0-1,5-6", "items=0-1", "bytes=x-y"])
def test_unhandled_range_serves_whole_object(ranged, spec):
    r = ranged(spec)
    assert r.status == 200
    assert r.body == BODY


# ---- conditional reads ------------------------------------------------------

def test_if_none_match_on_read(s3, bucket, prefix):
    etag = s3.put(bucket, prefix + "obj", b"data").etag

    r = s3.get(bucket, prefix + "obj", headers={"If-None-Match": etag})
    assert r.status == 304
    assert r.body == b""
    assert r.etag == etag

    for value in ('"other"', etag.strip('"'), f"W/{etag}", f'"x", {etag}', "*"):
        expected = 200 if value == '"other"' else 304
        assert s3.get(bucket, prefix + "obj", headers={"If-None-Match": value}).status == expected, value


def test_if_match_on_read(s3, bucket, prefix):
    etag = s3.put(bucket, prefix + "obj", b"data").etag

    assert s3.get(bucket, prefix + "obj", headers={"If-Match": etag}).status == 200
    r = s3.get(bucket, prefix + "obj", headers={"If-Match": '"stale"'})
    assert r.status == 412
    assert r.error_code == "PreconditionFailed"
    assert s3.head(bucket, prefix + "obj", headers={"If-Match": '"stale"'}).status == 412
