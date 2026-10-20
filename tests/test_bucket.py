"""Service and bucket level operations, subresources and error shape."""
import secrets

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
    assert s3.head(env.other.name).status == 403
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
