"""The settings API behind the personal settings page."""
import secrets

import pytest
from s3client import S3Client


@pytest.fixture
def folder(nc, env):
    name = f"s3t-{env.run_id}-cfg-{secrets.token_hex(3)}"
    nc.mkcol(name)
    return name


def name():
    return f"cfg-{secrets.token_hex(4)}"


def test_requires_login(nc):
    assert nc.api("GET", "/buckets", auth=False).status == 401


def test_create_and_list(nc, folder):
    n = name()
    created = nc.create_bucket("/" + folder, n)
    assert created["bucketName"] == n
    assert created["folderPath"] == "/" + folder
    listed = {b["bucketName"]: b for b in nc.api("GET", "/buckets").json()}
    assert listed[n]["id"] == created["id"]


@pytest.mark.parametrize("bad", ["ab", "x" * 64, "Upper", "-lead", "trail-", "dot..dot", "under_score"])
def test_bucket_name_validation(nc, folder, bad):
    r = nc.api("POST", "/buckets", {"folderPath": "/" + folder, "bucketName": bad})
    assert r.status == 400
    assert "error" in r.json()


def test_missing_parameters(nc):
    assert nc.api("POST", "/buckets", {"bucketName": name()}).status == 400


def test_folder_must_exist_and_be_a_folder(nc, folder):
    r = nc.api("POST", "/buckets", {"folderPath": f"/{folder}/nope", "bucketName": name()})
    assert r.status == 400
    nc.upload(f"{folder}/file.txt", b"x")
    r = nc.api("POST", "/buckets", {"folderPath": f"/{folder}/file.txt", "bucketName": name()})
    assert r.status == 400


def test_duplicate_name_conflicts(nc, folder, env):
    r = nc.api("POST", "/buckets", {"folderPath": "/" + folder, "bucketName": env.main.name})
    assert r.status == 409
    assert env.main.name in r.json()["error"]


def test_dotdot_spelling_is_refused(nc, env):
    """Nextcloud does not resolve `..` in the folder-exists check, it refuses
    it -- so such a spelling never gets as far as the overlap check."""
    nc.mkdirs(f"{env.main.folder}/x")
    nc.mkdirs(f"{env.main.folder}/sub")
    r = nc.api("POST", "/buckets", {"folderPath": f"/{env.main.folder}/x/../sub", "bucketName": name()})
    assert r.status == 400


@pytest.mark.parametrize("spelling", [
    "/{main}", "{main}", "/{main}/", "/{main}//", "/{main}/./", "/{main}/sub", "//{main}/./sub/",
])
def test_overlapping_folders_conflict(nc, env, spelling):
    nc.mkdirs(f"{env.main.folder}/sub")
    r = nc.api("POST", "/buckets", {"folderPath": spelling.format(main=env.main.folder), "bucketName": name()})
    assert r.status == 409, r.body
    assert env.main.name in r.json()["error"]


def test_parent_of_a_bucket_conflicts(nc, env):
    parent = f"s3t-{env.run_id}-parent-{secrets.token_hex(2)}"
    nc.mkdirs(f"{parent}/child")
    nc.create_bucket(f"/{parent}/child", name())
    assert nc.api("POST", "/buckets", {"folderPath": "/" + parent, "bucketName": name()}).status == 409


def test_sibling_with_common_prefix_is_fine(nc, env):
    """`/x` and `/x2` share a string prefix, not a folder."""
    sibling = env.main.folder + "2"
    nc.mkcol(sibling)
    assert nc.api("POST", "/buckets", {"folderPath": "/" + sibling, "bucketName": name()}).status == 201


def test_keys(nc, folder, env):
    b = nc.create_bucket("/" + folder, name())
    k = nc.create_key(b["id"], "readonly", "label")
    assert k["permission"] == "readonly"
    assert k["label"] == "label"
    assert len(k["accessKey"]) == 20
    assert k["secretKey"]

    listed = nc.api("GET", f"/buckets/{b['id']}/keys").json()
    assert [x["accessKey"] for x in listed] == [k["accessKey"]]
    # The secret is shown once, at creation.
    assert "secretKey" not in listed[0]
    assert "secretKeyEnc" not in listed[0]


def test_invalid_permission(nc, folder):
    b = nc.create_bucket("/" + folder, name())
    assert nc.api("POST", f"/buckets/{b['id']}/keys", {"permission": "admin"}).status == 400


def test_other_users_cannot_see_or_use_a_bucket(nc, nc_bob, folder):
    b = nc.create_bucket("/" + folder, name())
    k = nc.create_key(b["id"])

    assert nc_bob.api("GET", f"/buckets/{b['id']}/keys").status == 404
    assert nc_bob.api("POST", f"/buckets/{b['id']}/keys", {"permission": "readwrite"}).status == 404
    assert nc_bob.api("DELETE", f"/buckets/{b['id']}/keys/{k['id']}").status == 404
    assert nc_bob.api("DELETE", f"/buckets/{b['id']}").status == 404
    assert b["id"] not in [x["id"] for x in nc_bob.api("GET", "/buckets").json()]


def test_delete_key_checks_the_bucket(nc, env, folder):
    b = nc.create_bucket("/" + folder, name())
    k = nc.create_key(b["id"])
    # Right key id, wrong bucket in the route.
    assert nc.api("DELETE", f"/buckets/{env.other.id}/keys/{k['id']}").status == 404
    assert nc.api("DELETE", f"/buckets/{b['id']}/keys/{k['id']}").status == 200


def test_deleted_key_stops_working(nc, env, folder):
    b = nc.create_bucket("/" + folder, name())
    k = nc.create_key(b["id"])
    client = S3Client(env.endpoint, k["accessKey"], k["secretKey"])
    assert client.put(b["bucketName"], "x", b"x").status == 200

    nc.api("DELETE", f"/buckets/{b['id']}/keys/{k['id']}")
    r = client.get(b["bucketName"], "x")
    assert r.status == 403
    assert r.error_code == "InvalidAccessKeyId"


def test_deleting_a_bucket_revokes_its_keys_and_keeps_the_files(nc, env, folder):
    b = nc.create_bucket("/" + folder, name())
    k = nc.create_key(b["id"])
    client = S3Client(env.endpoint, k["accessKey"], k["secretKey"])
    client.put(b["bucketName"], "kept.txt", b"still here")

    assert nc.api("DELETE", f"/buckets/{b['id']}").status == 200
    assert client.get(b["bucketName"], "kept.txt").error_code == "InvalidAccessKeyId"
    assert nc.download(f"{folder}/kept.txt").body == b"still here"


def test_nfd_spelling_of_a_bucket_folder_conflicts(nc, env):
    """Nextcloud resolves the NFD spelling to the same folder; the overlap
    check used to compare the strings."""
    parent = f"s3t-{env.run_id}-caf\u00e9-{secrets.token_hex(2)}"
    nc.mkcol(parent)
    nc.create_bucket("/" + parent, name())
    r = nc.api("POST", "/buckets", {"folderPath": "/" + parent.replace("\u00e9", "e\u0301"), "bucketName": name()})
    assert r.status == 409, r.body


@pytest.mark.parametrize("payload", [
    {"permission": "readonly", "label": "x" * 256},
    {"permission": ["readonly"]},
    {"permission": "readonly", "label": {"a": 1}},
], ids=["label-too-long", "permission-not-a-string", "label-not-a-string"])
def test_bad_key_parameters_are_client_errors(nc, folder, payload):
    """Used to be a database error or a TypeError: a 500."""
    b = nc.create_bucket("/" + folder, name())
    assert nc.api("POST", f"/buckets/{b['id']}/keys", payload).status == 400


def test_bad_bucket_parameters_are_client_errors(nc, folder):
    assert nc.api("POST", "/buckets", {"folderPath": ["/" + folder], "bucketName": name()}).status == 400
    assert nc.api("POST", "/buckets", {"folderPath": "/" + folder, "bucketName": 123}).status == 400
