"""The cleanup background job, and how object locks behave when held or dead.

Both act on state that only exists after time has passed or a request has
died -- an upload abandoned a week ago, a lock row whose request is gone -- so
the tests fake that state in the database and let the real code act on it.
"""
import hashlib
import json
import time

import pytest
from botocore.exceptions import ClientError


@pytest.fixture
def run_cleanup(occ):
    """Run CleanupJob now, the way cron would."""
    jobs = json.loads(occ("background-job:list", "--class", "OCA\\S3Api\\BackgroundJob\\CleanupJob",
                          "--output=json"))
    assert len(jobs) == 1, jobs
    return lambda: occ("background-job:execute", "--force-execute", str(jobs[0]["id"]))


def lock_key(nc, env, key):
    """ObjectLockService's row key for an object: a hash of its storage path."""
    return hashlib.sha256(f"/{nc.user}/files/{env.main.folder}/{key}".encode()).hexdigest()


def test_cleanup_job(boto, s3, nc, env, sql, run_cleanup, bucket, prefix):
    # An upload started long ago and never finished, and one in progress.
    abandoned, active = prefix + "abandoned.bin", prefix + "active.bin"
    old_id = boto.create_multipart_upload(Bucket=bucket, Key=abandoned)["UploadId"]
    boto.upload_part(Bucket=bucket, Key=abandoned, UploadId=old_id, PartNumber=1, Body=b"old")
    new_id = boto.create_multipart_upload(Bucket=bucket, Key=active)["UploadId"]
    boto.upload_part(Bucket=bucket, Key=active, UploadId=new_id, PartNumber=1, Body=b"new")
    assert sql("UPDATE oc_s3api_uploads SET created_at = ? WHERE upload_id = ?",
               "2000-01-01 00:00:00", old_id) == 1

    # A lock left by a request that died two hours ago, and one just taken.
    dead = hashlib.sha256(f"dead {prefix}".encode()).hexdigest()
    held = hashlib.sha256(f"held {prefix}".encode()).hexdigest()
    now = int(time.time())
    sql("INSERT INTO oc_s3api_locks (lock_key, acquired_at) VALUES (?, ?)", dead, now - 7200)
    sql("INSERT INTO oc_s3api_locks (lock_key, acquired_at) VALUES (?, ?)", held, now)

    # An ETag recorded for a file that is gone, next to one whose file exists.
    s3.put(bucket, prefix + "kept", b"kept")
    orphan = sql("SELECT MAX(fileid) + 1000000 AS id FROM oc_filecache")[0]["id"]
    sql("INSERT INTO oc_s3api_etags (file_id, etag, file_size, file_mtime) VALUES (?, ?, 0, 0)", orphan, "0" * 32)
    kept_etag_rows = ("SELECT COUNT(*) AS n FROM oc_s3api_etags e JOIN oc_filecache f ON f.fileid = e.file_id"
                      " WHERE f.path = ?", f"files/{env.main.folder}/{prefix}kept")
    assert sql(*kept_etag_rows) == [{"n": 1}]

    try:
        run_cleanup()

        with pytest.raises(ClientError) as e:
            boto.list_parts(Bucket=bucket, Key=abandoned, UploadId=old_id)
        assert e.value.response["Error"]["Code"] == "NoSuchUpload"
        # Its parts are gone too, and not into the trash bin.
        assert not nc.exists(f"{env.main.folder}/.s3-uploads/{old_id}")
        assert not [loc for loc in nc.trash() if old_id in loc]

        parts = boto.list_parts(Bucket=bucket, Key=active, UploadId=new_id)["Parts"]
        assert [p["PartNumber"] for p in parts] == [1]

        rows = sql("SELECT lock_key FROM oc_s3api_locks WHERE lock_key IN (?, ?)", dead, held)
        assert [r["lock_key"] for r in rows] == [held]

        assert sql("SELECT COUNT(*) AS n FROM oc_s3api_etags WHERE file_id = ?", orphan) == [{"n": 0}]
        assert sql(*kept_etag_rows) == [{"n": 1}]
    finally:
        sql("DELETE FROM oc_s3api_locks WHERE lock_key IN (?, ?)", dead, held)
        sql("DELETE FROM oc_s3api_etags WHERE file_id = ?", orphan)
        boto.abort_multipart_upload(Bucket=bucket, Key=active, UploadId=new_id)


def test_stale_lock_is_broken(s3, nc, env, sql, bucket, prefix):
    """A lock whose request died does not wedge the key: once it is older than
    two minutes, the next conditional write breaks it."""
    key = prefix + "obj"
    lk = lock_key(nc, env, key)
    sql("INSERT INTO oc_s3api_locks (lock_key, acquired_at) VALUES (?, ?)", lk, int(time.time()) - 300)

    started = time.monotonic()
    r = s3.put(bucket, key, b"x", headers={"If-None-Match": "*"})
    assert r.status == 200, r.body
    assert time.monotonic() - started < 5
    # Broken, then taken and released by the write -- had the key not matched,
    # the row would still be here.
    assert sql("SELECT COUNT(*) AS n FROM oc_s3api_locks WHERE lock_key = ?", lk) == [{"n": 0}]


def test_held_lock_holds_off_conditional_writes(s3, nc, env, sql, bucket, prefix):
    key = prefix + "obj"
    lk = lock_key(nc, env, key)
    sql("INSERT INTO oc_s3api_locks (lock_key, acquired_at) VALUES (?, ?)", lk, int(time.time()))
    try:
        started = time.monotonic()
        r = s3.put(bucket, key, b"x", headers={"If-None-Match": "*"})
        # Waits for the lock for ten seconds, then gives up retryably.
        assert r.status == 503
        assert r.error_code == "SlowDown"
        assert time.monotonic() - started >= 9
        assert s3.get(bucket, key).status == 404

        # Only conditional writes take the lock.
        assert s3.put(bucket, key, b"y").status == 200
    finally:
        sql("DELETE FROM oc_s3api_locks WHERE lock_key = ?", lk)

    etag = s3.head(bucket, key).etag
    assert s3.put(bucket, key, b"z", headers={"If-Match": etag}).status == 200
