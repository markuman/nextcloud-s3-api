"""Conditional writes: the compare-and-swap primitive gits3 builds on."""
import hashlib
import re
import secrets
import threading
import time
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


def file_id(nc, path):
    r = nc.http("PROPFIND", nc.dav_path(path),
                b'<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
                b"<d:prop><oc:fileid/></d:prop></d:propfind>", {"Depth": "0"})
    return int(re.search(rb"<oc:fileid>(\d+)</oc:fileid>", r.body).group(1))


def test_change_keeping_size_and_mtime_is_noticed(s3, nc, sql, container, datadir, env, bucket, prefix):
    """A recorded ETag used to stay current while size and mtime did, so a
    change that kept both went unnoticed and If-Match matched a version that
    was gone. Nextcloud's etag is compared too now: it changes with every
    write Nextcloud sees on storages whose etag is unique per write -- object
    storage as primary, most external storages. Simulated here: the bytes
    replaced on the disk with the mtime kept, and the new etag such a
    storage reports.

    Not closed by this on local storage: there Nextcloud's etag is itself
    md5(mtime, inode, device, size), and an in-place rewrite keeps all four."""
    key = prefix + "obj"
    old = s3.put(bucket, key, b"aaaa").etag
    path = f"{datadir}/{nc.user}/files/{env.main.folder}/{key}"
    container("sh", "-c", f"m=$(stat -c %Y '{path}') && printf bbbb > '{path}' && touch -d @$m '{path}'")
    sql("UPDATE oc_filecache SET etag = ? WHERE fileid = ?", secrets.token_hex(16), file_id(nc, f"{env.main.folder}/{key}"))

    assert s3.head(bucket, key).etag == f'"{hashlib.md5(b"bbbb").hexdigest()}"'
    r = s3.put(bucket, key, b"cccc", headers={"If-Match": old})
    assert r.status == 412
    assert s3.get(bucket, key).body == b"bbbb"


def test_unchanged_etag_of_nextcloud_keeps_the_record(s3, nc, sql, env, bucket, prefix):
    """The other direction, which gits3 depends on: as long as nothing
    changed, the recorded value is served as it is -- not hashed again."""
    key = prefix + "obj"
    etag = s3.put(bucket, key, b"aaaa").etag
    sql("UPDATE oc_s3api_etags SET etag = ? WHERE file_id = ?", "f" * 32, file_id(nc, f"{env.main.folder}/{key}"))
    assert s3.head(bucket, key).etag == '"' + "f" * 32 + '"'
    assert etag != '"' + "f" * 32 + '"'


def test_one_request_hashes_a_file_at_a_time(s3, nc, sql, env, bucket, prefix):
    """A file without a recorded ETag is hashed before the first answer. A
    request that finds another one hashing it waits briefly, then gets 503
    SlowDown, instead of starting a hash of its own -- client retries used
    to pile up hashes of one large file."""
    key = prefix + "external"
    nc.mkdirs(f"{env.main.folder}/{prefix}")
    nc.upload(f"{env.main.folder}/{key}", b"written via webdav")

    lock = hashlib.sha256(f"etag:{file_id(nc, f'{env.main.folder}/{key}')}".encode()).hexdigest()
    sql("INSERT INTO oc_s3api_locks (lock_key, acquired_at) VALUES (?, ?)", lock, int(time.time()))
    try:
        r = s3.get(bucket, key)
        assert r.status == 503
        assert r.error_code == "SlowDown"

        # A hasher that died -- timeout, OOM, restart -- blocks the file for a
        # minute, not for an object lock's fifteen.
        sql("UPDATE oc_s3api_locks SET acquired_at = ? WHERE lock_key = ?", int(time.time()) - 70, lock)
        r = s3.get(bucket, key)
        assert r.status == 200
        assert r.etag == f'"{hashlib.md5(b"written via webdav").hexdigest()}"'
    finally:
        sql("DELETE FROM oc_s3api_locks WHERE lock_key = ?", lock)
