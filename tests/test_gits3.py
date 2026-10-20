"""The S3 surface gits3 depends on, driven through gits3's own client.

gits3 (../gits3srv) keeps bare git repositories in a bucket and uses
`packed-refs` as a compare-and-swap manifest. Its S3 client is a hand-rolled
stdlib one with its own quirks -- urllib re-capitalises header names
(`If-none-match`, `X-amz-date`), only `host;x-amz-content-sha256;x-amz-date`
are signed, query strings are fully percent-encoded, and a PUT counts as
successful only on exactly 200. Rather than re-implementing that, these tests
import gits3.py and call its functions against the endpoint.

Skipped when gits3.py is not found (S3T_GITS3, default ../gits3srv/gits3.py).
"""
import importlib.util
import os
import secrets

import pytest

pytestmark = pytest.mark.gits3

PACKED_REFS = b"# pack-refs with: peeled fully-peeled sorted \n" + b"0" * 40 + b" refs/heads/main\n"


@pytest.fixture(scope="session")
def gits3_module(env, tmp_path_factory):
    path = os.environ.get("S3T_GITS3", "")
    if not os.path.isfile(path):
        pytest.skip(f"gits3.py not found at {path!r}")

    # gits3 configures itself from the environment at import time.
    overrides = {
        "GITS3_ENDPOINT": env.endpoint,
        "GITS3_BUCKET": env.main.name,
        "GITS3_PREFIX": "",
        "GITS3_CACHE": str(tmp_path_factory.mktemp("gits3-cache")),
        "GITS3_USERS": "",
        "AWS_ACCESS_KEY_ID": env.rw.access,
        "AWS_SECRET_ACCESS_KEY": env.rw.secret,
    }
    saved = {k: os.environ.get(k) for k in overrides}
    os.environ.update(overrides)
    try:
        spec = importlib.util.spec_from_file_location("gits3_under_test", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    finally:
        for k, v in saved.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
    return module


@pytest.fixture
def g(gits3_module, env, prefix):
    """gits3 pointed at a fresh GITS3_PREFIX."""
    gits3_module.s3 = gits3_module.S3(env.endpoint, env.main.name, prefix, "us-east-1",
                                      env.rw.access, env.rw.secret)
    gits3_module.forget_index()
    return gits3_module


def test_startup_probe(g, s3, bucket, prefix):
    """check_backend() exits the process unless conditional writes and ETags
    work. Run twice: the second start finds the probe already there."""
    g.check_backend()
    g.check_backend()
    r = s3.get(bucket, prefix + ".gits3-probe")
    assert r.body == b"gits3\n"


def test_first_push_sequence(g, tmp_path):
    """push_up() for a new repo: packs, idx, scaffold, then packed-refs as
    create-only. Then an update by ETag, and a stale one that must lose."""
    repo = "first.git"
    st, _, _ = g.s3.get(f"{repo}/packed-refs")
    assert st == 404

    stem = "pack-" + secrets.token_hex(20)
    for ext, data in ((".pack", os.urandom(200_000)), (".idx", os.urandom(2_000))):
        path = tmp_path / (stem + ext)
        path.write_bytes(data)
        with open(path, "rb") as f:  # file object: gits3 sizes it via fstat
            g.s3.put(f"{repo}/objects/pack/{stem}{ext}", f)

    g.create_remote(repo)
    g.create_remote(repo)  # scaffold exists: 412s are swallowed
    g.s3.put(f"{repo}/packed-refs", PACKED_REFS, {"If-None-Match": "*"})

    with pytest.raises(g.S3Error) as e:
        g.s3.put(f"{repo}/packed-refs", PACKED_REFS, {"If-None-Match": "*"})
    assert e.value.status == 412

    st, h, body = g.s3.get(f"{repo}/packed-refs")
    assert st == 200 and body == PACKED_REFS
    etag = h["ETag"]
    assert etag.startswith('"') and etag.endswith('"')

    updated = PACKED_REFS + b"1" * 40 + b" refs/heads/dev\n"
    g.s3.put(f"{repo}/packed-refs", updated, {"If-Match": etag})
    with pytest.raises(g.S3Error) as e:
        g.s3.put(f"{repo}/packed-refs", PACKED_REFS, {"If-Match": etag})
    assert e.value.status == 412

    assert sorted(g.s3.ls(f"{repo}/objects/pack/")) == [stem + ".idx", stem + ".pack"]
    for name, expected in (("HEAD", b"ref: refs/heads/main\n"), ("refs/.keep", b""), ("objects/info/.keep", b"")):
        st, _, body = g.s3.get(f"{repo}/{name}")
        assert (st, body) == (200, expected), name


def test_ls_lists_direct_children_only(g):
    for k in ("r.git/objects/pack/a.pack", "r.git/objects/pack/a.idx", "r.git/objects/pack/sub/x"):
        g.s3.put(k, b"x")
    assert sorted(g.s3.ls("r.git/objects/pack/")) == ["a.idx", "a.pack"]


def test_objects_follows_continuation_tokens(g, gits3_module, env, many_keys):
    p, keys = many_keys
    g.s3 = gits3_module.S3(env.endpoint, env.main.name, p, "us-east-1", env.rw.access, env.rw.secret)
    listed = g.s3.objects("")
    assert [k for k, _ in listed] == [k[len(p):] for k in keys]
    assert all(lm.endswith("Z") for _, lm in listed)


def test_list_repos(g, nc, env, prefix):
    g.s3.put("old.git/packed-refs", PACKED_REFS)
    g.s3.put("new.git/packed-refs", PACKED_REFS)
    g.s3.put("new.git/objects/pack/p.pack", b"x")
    g.s3.put("not-a-repo/packed-refs", PACKED_REFS)
    g.check_backend()  # leaves .gits3-probe next to the repos
    # The public marker is created in Nextcloud, not through S3.
    nc.upload(f"{env.main.folder}/{prefix}new.git/git-daemon-export-ok", b"")

    repos = {r["name"]: r for r in g.list_repos()}
    assert set(repos) == {"old.git", "new.git"}
    assert repos["new.git"]["public"] is True
    assert repos["old.git"]["public"] is False
    assert g.is_public("new.git") is True
    assert g.is_public("old.git") is False


def test_download_streams_to_file(g, tmp_path):
    data = os.urandom(3 * 1024 * 1024 + 17)
    g.s3.put("big.git/objects/pack/p.pack", data)
    g.s3.download("big.git/objects/pack/p.pack", str(tmp_path / "p.pack"))
    assert (tmp_path / "p.pack").read_bytes() == data


def test_gc_deletes(g):
    """gc deletes old packs one by one, .idx first, and accepts 200 or 204."""
    g.s3.put("gc.git/objects/pack/old.pack", b"x")
    g.s3.put("gc.git/objects/pack/old.idx", b"x")
    for name in ("old.idx", "old.pack", "old.pack"):  # the last one is already gone
        st, _, _ = g.s3.req("DELETE", f"gc.git/objects/pack/{name}")
        assert st in (200, 204)
    assert g.s3.ls("gc.git/objects/pack/") == []
