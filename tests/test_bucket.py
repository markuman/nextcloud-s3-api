"""Service and bucket level operations, subresources and error shape."""
import secrets
import time
import urllib.parse

import pytest
from s3client import NS


def test_list_buckets_shows_only_the_scoped_bucket(s3, env):
    r = s3.request("GET")
    assert r.status == 200
    names = [b.findtext(f"{NS}Name") for b in r.xml().iter(f"{NS}Bucket")]
    assert names == [env.main.name]


def test_list_buckets_via_boto3(boto, env):
    assert [b["Name"] for b in boto.list_buckets()["Buckets"]] == [env.main.name]


def test_head_bucket(s3, env):
    assert s3.head(env.main.name).status == 200
    assert s3.head(env.other.name).status == 404
    assert s3.head("no-such-bucket-here").status == 404


def test_location_and_versioning(s3, boto, bucket):
    r = s3.request("GET", bucket, query={"location": ""})
    assert r.xml().text == "us-east-1"
    assert boto.get_bucket_location(Bucket=bucket)["LocationConstraint"] == "us-east-1"

    r = s3.request("GET", bucket, query={"versioning": ""})
    assert r.status == 200
    assert r.xml().tag == f"{NS}VersioningConfiguration"
    assert "Status" not in boto.get_bucket_versioning(Bucket=bucket)


@pytest.mark.parametrize("sub", ["acl", "tagging", "policy", "lifecycle", "versions"])
def test_unsupported_bucket_subresource(s3, bucket, sub):
    r = s3.request("GET", bucket, query={sub: ""})
    assert r.status == 501
    assert r.error_code == "NotImplemented"


@pytest.mark.parametrize("sub", ["acl", "tagging", "retention"])
def test_unsupported_object_subresource_does_not_touch_the_object(s3, bucket, prefix, sub):
    s3.put(bucket, prefix + "obj", b"precious")
    r = s3.put(bucket, prefix + "obj", b"<Tagging/>", query={sub: ""})
    assert r.status == 501
    assert r.error_code == "NotImplemented"
    assert s3.get(bucket, prefix + "obj").body == b"precious"


@pytest.mark.parametrize("method", ["PUT", "DELETE", "POST"])
def test_bucket_methods_not_allowed(s3, bucket, method):
    r = s3.request(method, bucket)
    assert r.status == 405
    assert r.error_code == "MethodNotAllowed"


def test_known_subresource_with_wrong_method(s3, bucket):
    r = s3.request("PUT", bucket, query={"versioning": ""})
    assert r.status == 405


def test_error_body_shape(s3, bucket, prefix):
    r = s3.get(bucket, prefix + "missing")
    assert r.header("content-type").startswith("application/xml")
    root = r.xml()
    assert root.tag == "Error"
    assert root.findtext("Code") == "NoSuchKey"
    assert root.findtext("Message")
    assert root.findtext("Resource") == f"/{bucket}/{prefix}missing"
    assert root.findtext("RequestId")


def test_bucket_whose_folder_is_gone(nc, env):
    folder = f"s3t-{env.run_id}-gone-{secrets.token_hex(2)}"
    nc.mkcol(folder)
    b = nc.create_bucket("/" + folder, f"gone-{secrets.token_hex(3)}")
    k = nc.create_key(b["id"])
    nc.remove(folder)

    from s3client import S3Client
    client = S3Client(env.endpoint, k["accessKey"], k["secretKey"])
    r = client.get(b["bucketName"], "x")
    assert r.status == 404
    assert r.error_code == "NoSuchBucket"


# ---- the bucket is its folder, not a path -------------------------------------

@pytest.fixture
def own_bucket(nc, env):
    from s3client import S3Client
    folder = f"s3t-{env.run_id}-mv-{secrets.token_hex(2)}"
    nc.mkcol(folder)
    b = nc.create_bucket("/" + folder, f"mv-{secrets.token_hex(3)}")
    k = nc.create_key(b["id"])
    client = S3Client(env.endpoint, k["accessKey"], k["secretKey"])
    assert client.put(b["bucketName"], "obj", b"original").status == 200
    return folder, b, client


def move(nc, src, dst):
    r = nc.http("MOVE", nc.dav_path(src), headers={"Destination": nc.base_url + nc.dav_path(dst)})
    assert r.status in (201, 204), r.status


def test_bucket_follows_its_folder(nc, own_bucket):
    """Up to 1.6.1 a bucket was its path: renamed, it answered NoSuchBucket,
    and whatever later appeared under the old name -- a new folder, or a share
    from someone else mounted there -- was served to its keys instead."""
    folder, b, client = own_bucket
    move(nc, folder, folder + "-renamed")
    assert client.get(b["bucketName"], "obj").body == b"original"

    nc.mkcol(folder)
    nc.upload(f"{folder}/obj", b"impostor")
    assert client.get(b["bucketName"], "obj").body == b"original"
    assert client.put(b["bucketName"], "new", b"x").status == 200
    assert nc.exists(f"{folder}-renamed/new")
    assert not nc.exists(f"{folder}/new")

    # The settings page shows where the folder is now, and the overlap check
    # knows it too.
    listed = {x["id"]: x for x in nc.api("GET", "/buckets").json()}
    assert listed[b["id"]]["folderPath"] == f"/{folder}-renamed"
    r = nc.api("POST", "/buckets", {"folderPath": f"/{folder}-renamed", "bucketName": f"mv2-{secrets.token_hex(3)}"})
    assert r.status == 409


def test_bucket_from_before_folder_ids_is_bound_on_use(nc, sql, own_bucket):
    """What the migration could not resolve is bound by path on first use,
    and stays bound from then on."""
    folder, b, client = own_bucket
    (row,) = sql("SELECT folder_id FROM oc_s3api_buckets WHERE id = ?", b["id"])
    sql("UPDATE oc_s3api_buckets SET folder_id = NULL WHERE id = ?", b["id"])

    assert client.get(b["bucketName"], "obj").body == b"original"
    assert sql("SELECT folder_id FROM oc_s3api_buckets WHERE id = ?", b["id"]) == [row]

    move(nc, folder, folder + "-renamed")
    assert client.get(b["bucketName"], "obj").body == b"original"


def share_with(owner, folder, recipient):
    """Share `folder` of `owner` with `recipient`; returns where it is mounted
    for the recipient, relative to their home."""
    body = urllib.parse.urlencode({"path": "/" + folder, "shareType": "0", "shareWith": recipient.user}).encode()
    r = owner.http("POST", "/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json", body,
                   {"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"})
    assert r.status == 200, r.body
    data = r.json()["ocs"]["data"]
    return data["id"], data["file_target"].strip("/")


def unshare(owner, share_id):
    owner.http("DELETE", f"/ocs/v2.php/apps/files_sharing/api/v1/shares/{share_id}")


def test_legacy_bucket_is_not_bound_to_a_newer_share(nc, nc_bob, sql, own_bucket):
    """The attack the folder id stops, as it may already stand when 1.6.2 is
    installed: the bucket's folder renamed away, and another user's share
    mounted under the old name since. Binding by path would make it stick."""
    folder, b, client = own_bucket
    move(nc, folder, folder + "-renamed")
    time.sleep(1.1)  # share times have second resolution
    nc_bob.mkcol(folder)
    nc_bob.upload(f"{folder}/obj", b"bob's")
    share_id, target = share_with(nc_bob, folder, nc)
    try:
        assert target == folder
        sql("UPDATE oc_s3api_buckets SET folder_id = NULL WHERE id = ?", b["id"])

        r = client.get(b["bucketName"], "obj")
        assert r.status == 404
        assert r.error_code == "NoSuchBucket"
        assert sql("SELECT folder_id FROM oc_s3api_buckets WHERE id = ?", b["id"]) == [{"folder_id": None}]
    finally:
        unshare(nc_bob, share_id)
        nc_bob.remove(folder)


def test_legacy_bucket_on_a_share_is_bound(nc, nc_bob, sql, env):
    """A bucket registered on a share that was already there is the normal
    case, and binds as before."""
    from s3client import S3Client
    folder = f"s3t-{env.run_id}-shared-{secrets.token_hex(2)}"
    nc_bob.mkcol(folder)
    nc_bob.upload(f"{folder}/obj", b"shared")
    share_id, target = share_with(nc_bob, folder, nc)
    try:
        time.sleep(1.1)
        b = nc.create_bucket("/" + target, f"sh-{secrets.token_hex(3)}")
        k = nc.create_key(b["id"])
        client = S3Client(env.endpoint, k["accessKey"], k["secretKey"])
        sql("UPDATE oc_s3api_buckets SET folder_id = NULL WHERE id = ?", b["id"])

        assert client.get(b["bucketName"], "obj").body == b"shared"
        (row,) = sql("SELECT folder_id FROM oc_s3api_buckets WHERE id = ?", b["id"])
        assert row["folder_id"] is not None
    finally:
        unshare(nc_bob, share_id)
        nc_bob.remove(folder)
