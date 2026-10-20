"""Conditional writes: the compare-and-swap primitive gits3 and walgit build on."""
import threading
from concurrent.futures import ThreadPoolExecutor

import pytest


def test_create_only(s3, bucket, prefix):
    key = prefix + "obj"
    assert s3.put(bucket, key, b"first", headers={"If-None-Match": "*"}).status == 200

    r = s3.put(bucket, key, b"second", headers={"If-None-Match": "*"})
    assert r.status == 412
    assert r.error_code == "PreconditionFailed"
    assert s3.get(bucket, key).body == b"first"


def test_replace_exact_version(s3, bucket, prefix):
    key = prefix + "obj"
    v1 = s3.put(bucket, key, b"v1").etag
    v2 = s3.put(bucket, key, b"v2", headers={"If-Match": v1})
    assert v2.status == 200

    stale = s3.put(bucket, key, b"v3", headers={"If-Match": v1})
    assert stale.status == 412
    assert s3.get(bucket, key).body == b"v2"


def test_if_match_on_missing_key(s3, bucket, prefix):
    r = s3.put(bucket, prefix + "nope", b"x", headers={"If-Match": '"abc"'})
    assert r.status == 412
    assert s3.get(bucket, prefix + "nope").status == 404


def test_if_match_star(s3, bucket, prefix):
    key = prefix + "obj"
    assert s3.put(bucket, key, b"x", headers={"If-Match": "*"}).status == 412
    s3.put(bucket, key, b"x")
    assert s3.put(bucket, key, b"y", headers={"If-Match": "*"}).status == 200


@pytest.mark.parametrize("form", ["quoted", "bare", "weak", "list"])
def test_if_match_forms(s3, bucket, prefix, form):
    key = prefix + "obj"
    etag = s3.put(bucket, key, b"v1").etag
    value = {
        "quoted": etag,
        "bare": etag.strip('"'),
        "weak": f"W/{etag}",
        "list": f'"nope", {etag}',
    }[form]
    assert s3.put(bucket, key, b"v2", headers={"If-Match": value}).status == 200


def test_if_none_match_etag_on_write(s3, bucket, prefix):
    key = prefix + "obj"
    etag = s3.put(bucket, key, b"v1").etag
    assert s3.put(bucket, key, b"v2", headers={"If-None-Match": etag}).status == 412
    assert s3.put(bucket, key, b"v2", headers={"If-None-Match": '"other"'}).status == 200


def test_failed_precondition_leaves_no_trace(s3, bucket, prefix):
    """A refused create must not leave the parent folders it would have needed
    as a visible object, nor change anything."""
    key = prefix + "obj"
    s3.put(bucket, key, b"keep")
    s3.put(bucket, key, b"lose", headers={"If-None-Match": "*"})
    assert s3.list_all(bucket, prefix) == [key]


def _race(n, fn):
    barrier = threading.Barrier(n)

    def go(i):
        barrier.wait()
        return fn(i)

    with ThreadPoolExecutor(n) as pool:
        return list(pool.map(go, range(n)))


def test_racing_creates_have_one_winner(s3, bucket, prefix):
    key = prefix + "packed-refs"
    results = _race(12, lambda i: s3.put(bucket, key, f"writer {i}".encode(), headers={"If-None-Match": "*"}))

    statuses = [r.status for r in results]
    assert statuses.count(200) == 1, statuses
    assert set(statuses) <= {200, 412}, statuses

    winner = statuses.index(200)
    assert s3.get(bucket, key).body == f"writer {winner}".encode()


def test_racing_updates_have_one_winner(s3, bucket, prefix):
    key = prefix + "packed-refs"
    base = s3.put(bucket, key, b"base").etag
    results = _race(12, lambda i: s3.put(bucket, key, f"writer {i}".encode(), headers={"If-Match": base}))

    statuses = [r.status for r in results]
    assert statuses.count(200) == 1, statuses
    assert set(statuses) <= {200, 412}, statuses

    winner = statuses.index(200)
    r = s3.get(bucket, key)
    assert r.body == f"writer {winner}".encode()
    assert r.etag == results[winner].etag
