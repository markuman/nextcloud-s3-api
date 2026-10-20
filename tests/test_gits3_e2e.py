"""End to end: real git, through a real gits3 server, into this app.

Starts gits3.py as a subprocess against the test bucket and pushes, clones,
garbage-collects and clones again with an empty cache -- the last clone can
only succeed if everything gits3 put into the bucket comes back intact.

Skipped when gits3.py or git is not available.
"""
import os
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from contextlib import contextmanager

import pytest

pytestmark = pytest.mark.gits3

GIT_ENV = {
    "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
    "HOME": "/nonexistent",
    "GIT_CONFIG_GLOBAL": os.devnull,
    "GIT_CONFIG_NOSYSTEM": "1",
    "GIT_TERMINAL_PROMPT": "0",
    "GIT_AUTHOR_NAME": "pytest",
    "GIT_AUTHOR_EMAIL": "pytest@example.invalid",
    "GIT_COMMITTER_NAME": "pytest",
    "GIT_COMMITTER_EMAIL": "pytest@example.invalid",
}


def git(*args, cwd=None, check=True):
    r = subprocess.run(["git", *args], cwd=cwd, env=GIT_ENV, capture_output=True, text=True, check=False)
    if check and r.returncode:
        raise AssertionError(f"git {' '.join(args)} failed:\n{r.stdout}\n{r.stderr}")
    return r


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


@pytest.fixture(scope="module")
def gits3_path():
    path = os.environ.get("S3T_GITS3", "")
    if not os.path.isfile(path):
        pytest.skip(f"gits3.py not found at {path!r}")
    if not shutil.which("git"):
        pytest.skip("git not installed")
    return path


@pytest.fixture
def server(gits3_path, env, prefix, tmp_path):
    """Factory: start a gits3 instance on the test prefix with its own cache."""
    procs = []

    @contextmanager
    def start():
        port = free_port()
        log_path = tmp_path / f"gits3-{port}.log"
        with open(log_path, "wb") as log:
            proc = subprocess.Popen(
                [sys.executable, gits3_path],
                env={
                    **GIT_ENV,
                    "GITS3_ENDPOINT": env.endpoint,
                    "GITS3_BUCKET": env.main.name,
                    "GITS3_PREFIX": prefix,
                    "GITS3_CACHE": str(tmp_path / f"cache-{port}"),
                    "GITS3_LISTEN": f"127.0.0.1:{port}",
                    "GITS3_USERS": "writer:wpw:rw reader:rpw:ro",
                    "GITS3_INDEX_TTL": "0",
                    "AWS_ACCESS_KEY_ID": env.rw.access,
                    "AWS_SECRET_ACCESS_KEY": env.rw.secret,
                },
                stdout=log, stderr=subprocess.STDOUT,
            )
            procs.append(proc)
            deadline = time.monotonic() + 30
            while True:
                if proc.poll() is not None:
                    log.flush()
                    raise AssertionError("gits3 exited:\n" + log_path.read_text())
                try:
                    socket.create_connection(("127.0.0.1", port), timeout=1).close()
                    break
                except OSError:
                    if time.monotonic() > deadline:
                        raise AssertionError("gits3 did not start listening") from None
                    time.sleep(0.2)
            try:
                yield f"127.0.0.1:{port}"
            finally:
                proc.terminate()
                proc.wait(10)

    yield start
    for p in procs:
        if p.poll() is None:
            p.kill()


def commit(work, name, content):
    (work / name).write_text(content)
    git("add", name, cwd=work)
    git("commit", "-q", "-m", f"add {name}", cwd=work)
    return git("rev-parse", "HEAD", cwd=work).stdout.strip()


def pack_pairs(s3, bucket, prefix):
    keys = s3.list_all(bucket, prefix + "e2e.git/objects/pack/")
    return sorted(k.rsplit("/", 1)[1][:-5] for k in keys if k.endswith(".pack")), keys


def http_post(url, user, password):
    import base64
    req = urllib.request.Request(url, method="POST", data=b"")
    req.add_header("Authorization", "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode())
    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            return resp.status, resp.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def test_push_clone_gc_clone(server, s3, nc, env, bucket, prefix, tmp_path):
    work = tmp_path / "work"
    git("init", "-q", "-b", "main", str(work))
    for i in range(3):
        head = commit(work, f"file{i}.txt", f"content {i}\n" * 100)

    with server() as host:
        push_url = f"http://writer:wpw@{host}/e2e.git"
        git("push", "-q", push_url, "main", cwd=work)

        listed = s3.list_all(bucket, prefix + "e2e.git/")
        rel = sorted(k[len(prefix + "e2e.git/"):] for k in listed)
        assert [k for k in rel if not k.startswith("objects/pack/")] == [
            "HEAD", "config", "objects/info/.keep", "packed-refs", "refs/.keep"]
        packs, _ = pack_pairs(s3, bucket, prefix)
        assert len(packs) == 1

        # Second push: incremental, a second pack pair, CAS on packed-refs.
        head = commit(work, "more.txt", "more\n")
        git("push", "-q", push_url, "main", cwd=work)
        packs, _ = pack_pairs(s3, bucket, prefix)
        assert len(packs) == 2

        # A read-only login can clone through the same instance.
        clone = tmp_path / "clone1"
        git("clone", "-q", f"http://reader:rpw@{host}/e2e.git", str(clone))
        assert git("rev-parse", "HEAD", cwd=clone).stdout.strip() == head

    # A new instance with an empty cache rebuilds everything from the bucket.
    with server() as host:
        clone = tmp_path / "clone2"
        git("clone", "-q", f"http://reader:rpw@{host}/e2e.git", str(clone))
        assert git("rev-parse", "HEAD", cwd=clone).stdout.strip() == head
        git("fsck", "--strict", "--no-dangling", cwd=clone)

        # gc: one pack, old ones deleted from the bucket (DeleteObject, 204).
        status, body = http_post(f"http://{host}/e2e.git/gc", "writer", "wpw")
        assert status == 200, body
        packs, keys = pack_pairs(s3, bucket, prefix)
        assert len(packs) == 1, keys
        assert len(keys) == 2, keys

        # Private: anonymous clones fail until the marker exists in Nextcloud.
        anon = git("clone", "-q", f"http://{host}/e2e.git", str(tmp_path / "anon"), check=False)
        assert anon.returncode != 0

    nc.upload(f"{env.main.folder}/{prefix}e2e.git/git-daemon-export-ok", b"")

    with server() as host:
        clone = tmp_path / "clone3"
        git("clone", "-q", f"http://{host}/e2e.git", str(clone))
        assert git("rev-parse", "HEAD", cwd=clone).stdout.strip() == head
        git("fsck", "--strict", "--no-dangling", cwd=clone)
        assert sorted(p.name for p in clone.iterdir() if p.suffix == ".txt") == [
            "file0.txt", "file1.txt", "file2.txt", "more.txt"]


def test_second_instance_keeps_the_first_ones_refs(server, s3, bucket, prefix, tmp_path):
    """Two gits3 instances on one bucket, used one after the other: the second
    has a stale cache when the push arrives and must pick up the first one's
    refs from the bucket rather than overwrite them.

    This is the sequential case. The truly concurrent one is decided by the
    If-Match on packed-refs, covered in test_conditional.py.
    """
    work = tmp_path / "work"
    git("init", "-q", "-b", "main", str(work))
    commit(work, "base.txt", "base\n")

    with server() as a, server() as b:
        git("push", "-q", f"http://writer:wpw@{a}/e2e.git", "main", cwd=work)
        git("ls-remote", f"http://reader:rpw@{b}/e2e.git", cwd=work)  # b caches this state

        git("checkout", "-q", "-b", "left", cwd=work)
        left = commit(work, "left.txt", "left\n")
        git("push", "-q", f"http://writer:wpw@{a}/e2e.git", "left", cwd=work)

        git("checkout", "-q", "-b", "right", "main", cwd=work)
        right = commit(work, "right.txt", "right\n")
        git("push", "-q", f"http://writer:wpw@{b}/e2e.git", "right", cwd=work)

    refs = s3.get(bucket, prefix + "e2e.git/packed-refs").body.decode()
    assert f"{left} refs/heads/left" in refs
    assert f"{right} refs/heads/right" in refs
