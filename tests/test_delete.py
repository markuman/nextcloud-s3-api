"""DeleteObject and DeleteObjects."""
from s3client import NS


def delete_body(keys, quiet=False):
    objects = "".join(f"<Object><Key>{k}</Key></Object>" for k in keys)
    q = "<Quiet>true</Quiet>" if quiet else ""
    return f'<?xml version="1.0" encoding="UTF-8"?><Delete>{q}{objects}</Delete>'.encode()


def bulk_delete(s3, bucket, keys, quiet=False):
    return s3.request("POST", bucket, body=delete_body(keys, quiet), query={"delete": ""},
                      headers={"Content-Type": "application/xml"})


def test_delete_object(s3, bucket, prefix):
    s3.put(bucket, prefix + "obj", b"x")
    r = s3.delete(bucket, prefix + "obj")
    assert r.status == 204
    assert s3.get(bucket, prefix + "obj").status == 404


def test_delete_missing_key_succeeds(s3, bucket, prefix):
    # gits3's gc accepts 200 or 204, and S3 never reports a missing key.
    assert s3.delete(bucket, prefix + "never-was").status == 204


def test_delete_folder_key_is_a_noop(s3, nc, env, bucket, prefix):
    """Regression (1.5.4): a key naming a folder used to trash the whole tree."""
    children = [prefix + "repos/a.git/HEAD", prefix + "repos/b.git/HEAD"]
    for k in children:
        s3.put(bucket, k, b"ref: refs/heads/main\n")

    r = s3.delete(bucket, prefix + "repos")
    assert r.status == 204
    assert s3.list_all(bucket, prefix) == sorted(children)
    assert nc.exists(f"{env.main.folder}/{prefix}repos/a.git/HEAD")


def test_recreated_key_gets_fresh_etag(s3, bucket, prefix):
    s3.put(bucket, prefix + "obj", b"old")
    s3.delete(bucket, prefix + "obj")
    etag = s3.put(bucket, prefix + "obj", b"new").etag
    assert s3.head(bucket, prefix + "obj").etag == etag


def test_bulk_delete(s3, bucket, prefix):
    keep = prefix + "dir/keep"
    gone = [prefix + "a", prefix + "b/c"]
    for k in gone + [keep]:
        s3.put(bucket, k, b"x")

    r = bulk_delete(s3, bucket, gone + [prefix + "missing", prefix + "dir", prefix + "bad/"])
    assert r.status == 200
    root = r.xml()
    deleted = [d.findtext(f"{NS}Key") for d in root.iter(f"{NS}Deleted")]
    errors = {e.findtext(f"{NS}Key"): e.findtext(f"{NS}Code") for e in root.iter(f"{NS}Error")}

    # Missing keys and folder keys count as deleted, as on S3 -- but the
    # folder must survive (same regression as DeleteObject).
    assert sorted(deleted) == sorted(gone + [prefix + "missing", prefix + "dir"])
    assert errors == {prefix + "bad/": "InvalidArgument"}
    assert s3.list_all(bucket, prefix) == [keep]


def test_bulk_delete_quiet(s3, bucket, prefix):
    s3.put(bucket, prefix + "a", b"x")
    root = bulk_delete(s3, bucket, [prefix + "a"], quiet=True).xml()
    assert list(root.iter(f"{NS}Deleted")) == []
    assert s3.get(bucket, prefix + "a").status == 404


def test_bulk_delete_malformed(s3, bucket):
    r = s3.request("POST", bucket, body=b"<Delete><Object>", query={"delete": ""})
    assert r.status == 400
    assert r.error_code == "MalformedXML"


def test_bulk_delete_is_capped_at_1000_keys(s3, bucket, prefix):
    s3.put(bucket, prefix + "k0", b"x")
    r = bulk_delete(s3, bucket, [prefix + f"k{i}" for i in range(1001)])
    assert r.status == 400
    assert r.error_code == "MalformedXML"
    assert s3.get(bucket, prefix + "k0").status == 200


def test_bulk_delete_via_boto3(boto, s3, bucket, prefix):
    for k in ("x", "y"):
        s3.put(bucket, prefix + k, b"1")
    out = boto.delete_objects(Bucket=bucket, Delete={"Objects": [{"Key": prefix + "x"}, {"Key": prefix + "y"}]})
    assert sorted(d["Key"] for d in out["Deleted"]) == [prefix + "x", prefix + "y"]
    assert s3.list_all(bucket, prefix) == []
