"""occ s3api:bucket:create: pre-provisioning buckets for users and groups."""
import secrets
import subprocess

import pytest
from s3client import S3Client
from test_bucket import move, share_with, unshare


def bucket_of(nc, name):
    return next(b for b in nc.api("GET", "/buckets").json() if b["bucketName"] == name)


def output(err):
    """Everything a failed occ run printed: the messages are on stdout today,
    but moving errors to stderr must not make these checks pass vacuously."""
    return err.value.stdout + err.value.stderr


def test_whole_home_is_refused(occ, make_user):
    """A bucket on the home blocks every other one; for a whole group that is
    a typo, not a plan."""
    nc = make_user()
    for folder in ("/", "/.", "//"):
        with pytest.raises(subprocess.CalledProcessError) as err:
            occ("s3api:bucket:create", "--user", nc.user, "--folder", folder,
                "--name", f"occ-{secrets.token_hex(4)}", "--create-folder")
        assert "whole home" in output(err)
    assert nc.api("GET", "/buckets").json() == []


def test_user_in_group_and_given_directly_is_provisioned_once(occ, make_user):
    alice, bob = make_user(), make_user()
    gid = f"s3t-{secrets.token_hex(4)}"
    occ("group:add", gid)
    occ("group:adduser", gid, alice.user, bob.user)
    try:
        name = f"occ-{secrets.token_hex(4)}"
        folder = f"s3t-occ-{secrets.token_hex(3)}"
        out = occ("s3api:bucket:create", "--user", alice.user, "--group", gid,
                  "--folder", "/" + folder, "--name", name, "--create-folder")
        assert out.count(f"for {alice.user} ") == 1
        assert "Skipped" not in out
        assert len(alice.api("GET", "/buckets").json()) == 1
        assert bucket_of(bob, name)["folderPath"] == "/" + folder
    finally:
        occ("group:delete", gid)


def test_creates_bucket_and_folder_for_a_user(occ, make_user, base_url):
    nc = make_user()
    name = f"occ-{secrets.token_hex(4)}"
    folder = f"s3t-occ-{secrets.token_hex(3)}"

    out = occ("s3api:bucket:create", "--user", nc.user,
              "--folder", "/" + folder, "--name", name, "--create-folder")
    assert nc.user in out

    # The folder was created in the user's home, and the bucket config shows
    # up in their settings, bound to it. No key came with it: the user creates
    # their own, exactly as on the settings page — and it works over S3.
    assert nc.exists(folder)
    bucket = bucket_of(nc, name)
    assert bucket["folderPath"] == "/" + folder
    assert nc.api("GET", f"/buckets/{bucket['id']}/keys").json() == []

    key = nc.create_key(bucket["id"], "readwrite")
    client = S3Client(base_url + "/apps/s3_api/s3", key["accessKey"], key["secretKey"])
    assert client.put(name, "hello.txt", b"hi").status == 200
    assert client.get(name, "hello.txt").body == b"hi"


def test_create_folder_makes_missing_parents(occ, make_user):
    nc = make_user()
    name = f"occ-{secrets.token_hex(4)}"
    occ("s3api:bucket:create", "--user", nc.user,
        "--folder", "/S3/nested/deep", "--name", name, "--create-folder")
    assert bucket_of(nc, name)["folderPath"] == "/S3/nested/deep"
    assert nc.exists("S3/nested/deep")


def test_existing_folder_needs_no_create_folder(occ, make_user):
    nc = make_user()
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    nc.mkcol(folder)
    name = f"occ-{secrets.token_hex(4)}"
    occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder, "--name", name)
    assert bucket_of(nc, name)["folderPath"] == "/" + folder


def test_group_covers_every_current_member(occ, make_user):
    alice, bob = make_user(), make_user()
    gid = f"s3t-{secrets.token_hex(4)}"
    occ("group:add", gid)
    occ("group:adduser", gid, alice.user, bob.user)
    try:
        name = f"occ-{secrets.token_hex(4)}"
        folder = f"s3t-occ-{secrets.token_hex(3)}"
        occ("s3api:bucket:create", "--group", gid,
            "--folder", "/" + folder, "--name", name, "--create-folder")
        for nc in (alice, bob):
            assert bucket_of(nc, name)["folderPath"] == "/" + folder
            assert nc.exists(folder)
    finally:
        occ("group:delete", gid)


def test_users_can_be_given_comma_separated(occ, make_user):
    alice, bob = make_user(), make_user()
    name = f"occ-{secrets.token_hex(4)}"
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    occ("s3api:bucket:create", "--user", f"{alice.user},{bob.user}",
        "--folder", "/" + folder, "--name", name, "--create-folder")
    assert bucket_of(alice, name)["folderPath"] == "/" + folder
    assert bucket_of(bob, name)["folderPath"] == "/" + folder


def test_rerun_skips_existing_buckets(occ, make_user):
    nc = make_user()
    name = f"occ-{secrets.token_hex(4)}"
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
        "--name", name, "--create-folder")
    out = occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
              "--name", name, "--create-folder")
    assert "Skipped" in out
    assert len(nc.api("GET", "/buckets").json()) == 1


def test_missing_folder_is_an_error_without_create_folder(occ, make_user):
    nc = make_user()
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/no-such-folder",
            "--name", f"occ-{secrets.token_hex(4)}")
    assert err.value.returncode != 0
    assert nc.api("GET", "/buckets").json() == []
    assert not nc.exists("no-such-folder")


def test_unknown_user_fails_before_creating_anything(occ, make_user):
    """A typo in one id must not leave half a rollout behind."""
    nc = make_user()
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", f"{nc.user},no-such-user",
            "--folder", "/" + folder, "--name", f"occ-{secrets.token_hex(4)}", "--create-folder")
    assert err.value.returncode != 0
    assert "no-such-user" in output(err)
    assert nc.api("GET", "/buckets").json() == []
    assert not nc.exists(folder)


def test_unknown_group_fails_before_creating_anything(occ, make_user):
    nc = make_user()
    with pytest.raises(subprocess.CalledProcessError):
        occ("s3api:bucket:create", "--user", nc.user, "--group", "no-such-group",
            "--folder", "/S3", "--name", f"occ-{secrets.token_hex(4)}", "--create-folder")
    assert nc.api("GET", "/buckets").json() == []


def test_one_failing_user_does_not_stop_the_others(occ, make_user):
    """Per-user conflicts are reported, the rest is provisioned, and the exit
    code says that not everything went through."""
    alice, bob = make_user(), make_user()
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    name = f"occ-{secrets.token_hex(4)}"
    alice.mkcol("taken")
    alice.create_bucket("/taken", name)
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", f"{alice.user},{bob.user}",
            "--folder", "/" + folder, "--name", name, "--create-folder")
    assert f"Created bucket \"{name}\" for {bob.user}" in output(err)
    assert bucket_of(bob, name)["folderPath"] == "/" + folder
    assert bucket_of(alice, name)["folderPath"] == "/taken"


def test_name_on_another_folder_is_an_error_not_a_skip(occ, make_user):
    """Same name, different folder: what the admin asked for did not happen,
    and no folder is created for nothing."""
    nc = make_user()
    name = f"occ-{secrets.token_hex(4)}"
    nc.mkcol("elsewhere")
    nc.create_bucket("/elsewhere", name)
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
            "--name", name, "--create-folder")
    assert "Skipped" not in output(err)
    assert "/elsewhere" in output(err)
    assert not nc.exists(folder)


def test_rerun_after_rename_does_not_recreate_the_folder(occ, make_user):
    """The bucket follows its folder; the old name being free again does not
    mean the bucket is missing."""
    nc = make_user()
    name = f"occ-{secrets.token_hex(4)}"
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
        "--name", name, "--create-folder")
    move(nc, folder, folder + "-renamed")
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
            "--name", name, "--create-folder")
    assert f"/{folder}-renamed" in output(err)
    assert not nc.exists(folder)
    assert bucket_of(nc, name)["folderPath"] == f"/{folder}-renamed"


def test_overlapping_folder_is_an_error_not_a_skip(occ, make_user):
    nc = make_user()
    nc.mkcol("outer")
    nc.create_bucket("/outer", f"outer-{secrets.token_hex(3)}")
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/outer/inner",
            "--name", f"occ-{secrets.token_hex(4)}", "--create-folder")
    assert "Skipped" not in output(err)
    assert "overlaps" in output(err)
    assert not nc.exists("outer/inner")
    assert len(nc.api("GET", "/buckets").json()) == 1


def test_incoming_share_is_refused(occ, make_user, nc_bob):
    """Whoever shares a folder named like the one picked for a group would
    receive everything its members upload."""
    nc = make_user()
    folder = f"s3t-occ-share-{secrets.token_hex(3)}"
    nc_bob.mkcol(folder)
    share_id, target = share_with(nc_bob, folder, nc)
    try:
        assert target == folder
        name = f"occ-{secrets.token_hex(4)}"
        with pytest.raises(subprocess.CalledProcessError) as err:
            occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
                "--name", name, "--create-folder")
        assert "share" in output(err)
        assert nc.api("GET", "/buckets").json() == []

        # Nor is a folder created inside it.
        with pytest.raises(subprocess.CalledProcessError):
            occ("s3api:bucket:create", "--user", nc.user, "--folder", f"/{folder}/sub",
                "--name", name, "--create-folder")
        assert not nc_bob.exists(f"{folder}/sub")
        assert nc.api("GET", "/buckets").json() == []

        # Unless the admin says so.
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
            "--name", name, "--allow-shared")
        assert bucket_of(nc, name)["folderPath"] == "/" + folder
    finally:
        unshare(nc_bob, share_id)
        nc_bob.remove(folder)


def test_dotdot_is_refused(occ, make_user):
    nc = make_user()
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/a/../b",
            "--name", f"occ-{secrets.token_hex(4)}", "--create-folder")
    assert ".." in output(err)
    assert not nc.exists("a")
    assert not nc.exists("b")


def test_path_to_a_file_is_refused(occ, make_user):
    nc = make_user()
    nc.upload("afile", b"x")
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/afile",
            "--name", f"occ-{secrets.token_hex(4)}", "--create-folder")
    assert "not a folder" in output(err)
    assert nc.api("GET", "/buckets").json() == []


def test_invalid_bucket_name_fails_before_creating_anything(occ, make_user):
    nc = make_user()
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    with pytest.raises(subprocess.CalledProcessError) as err:
        occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
            "--name", "UPPER", "--create-folder")
    assert err.value.returncode != 0
    assert nc.api("GET", "/buckets").json() == []
    assert not nc.exists(folder)


def test_bucket_is_bound_to_its_folder_id(occ, make_user, sql):
    nc = make_user()
    name = f"occ-{secrets.token_hex(4)}"
    folder = f"s3t-occ-{secrets.token_hex(3)}"
    occ("s3api:bucket:create", "--user", nc.user, "--folder", "/" + folder,
        "--name", name, "--create-folder")
    rows = sql("SELECT folder_id FROM oc_s3api_buckets WHERE user_id = ? AND bucket_name = ?",
               nc.user, name)
    assert rows[0]["folder_id"] is not None
