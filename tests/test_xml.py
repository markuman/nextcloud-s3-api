"""XML request bodies: DeleteObjects and CompleteMultipartUpload.

Both parse an untrusted body. Up to 1.5.4 they passed LIBXML_NOENT, which
despite its name switches entity substitution *on*: an internal entity was
expanded into a key, and libxml would have loaded external ones had Nextcloud
not installed a null entity loader. A body that declares a DTD is now refused
outright, since no S3 request carries one.
"""
import pytest
from s3client import NS

DELETE_WITH_DTD = {
    # expands to a key that exists -- used to delete it
    "internal-entity": '<!DOCTYPE Delete [<!ENTITY k "{key}">]>',
    "external-entity": '<!DOCTYPE Delete [<!ENTITY k SYSTEM "file:///etc/hostname">]>',
    "php-filter": '<!DOCTYPE Delete [<!ENTITY k SYSTEM '
                  '"php://filter/read=convert.base64-encode/resource=/var/www/html/config/config.php">]>',
    "parameter-entity": '<!DOCTYPE Delete [<!ENTITY % p SYSTEM "file:///etc/hostname"> %p;]>',
    "bare-doctype": '<!DOCTYPE Delete>',
}


def bulk_delete(s3, bucket, body):
    return s3.request("POST", bucket, body=body, query={"delete": ""},
                      headers={"Content-Type": "application/xml"})


@pytest.mark.parametrize("dtd", DELETE_WITH_DTD.values(), ids=DELETE_WITH_DTD.keys())
def test_bulk_delete_refuses_a_dtd(s3, bucket, prefix, dtd):
    key = prefix + "victim"
    s3.put(bucket, key, b"keep me")
    entity_key = "&k;" if "ENTITY k" in dtd else key
    body = (f'<?xml version="1.0" encoding="UTF-8"?>{dtd.format(key=key)}'
            f"<Delete><Object><Key>{entity_key}</Key></Object></Delete>").encode()

    r = bulk_delete(s3, bucket, body)
    assert r.status == 400, r.body
    assert r.error_code == "MalformedXML"
    assert s3.get(bucket, key).body == b"keep me"


def test_utf16_body_cannot_hide_its_dtd(s3, bucket, prefix):
    """The DTD is found in the parsed tree, not by searching the raw bytes,
    which would not contain `<!DOCTYPE` as ASCII here."""
    key = prefix + "victim"
    s3.put(bucket, key, b"keep me")
    body = (f'<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE Delete [<!ENTITY k "{key}">]>'
            "<Delete><Object><Key>&k;</Key></Object></Delete>").encode("utf-16")
    assert b"<!DOCTYPE" not in body

    r = bulk_delete(s3, bucket, body)
    assert r.status == 400
    assert r.error_code == "MalformedXML"
    assert s3.get(bucket, key).body == b"keep me"


def test_bulk_delete_without_dtd_still_parses(s3, bucket, prefix):
    """Comments, CDATA, predefined entities and character references need no
    DTD and keep working."""
    keys = [prefix + "a&b", prefix + "cdata", prefix + "d"]
    for k in keys:
        s3.put(bucket, k, b"x")
    body = ('<?xml version="1.0" encoding="UTF-8"?><!-- hello --><Delete>'
            f"<Object><Key>{prefix}a&amp;b</Key></Object>"
            f"<Object><Key><![CDATA[{prefix}cdata]]></Key></Object>"
            f"<Object><Key>{prefix}&#x64;</Key></Object>"
            "</Delete>").encode()

    r = bulk_delete(s3, bucket, body)
    assert r.status == 200, r.body
    assert sorted(d.findtext(f"{NS}Key") for d in r.xml().iter(f"{NS}Deleted")) == sorted(keys)
    assert s3.list_all(bucket, prefix) == []


def test_complete_refuses_a_dtd(s3, bucket, prefix):
    key = prefix + "big.bin"
    r = s3.request("POST", bucket, key, query={"uploads": ""}, raw_query="uploads")
    upload_id = r.xml().findtext(f"{NS}UploadId")
    etag = s3.put(bucket, key, b"only part", query={"partNumber": "1", "uploadId": upload_id}).etag

    def complete(body):
        return s3.request("POST", bucket, key, body.encode(), query={"uploadId": upload_id})

    parts = "<Part><PartNumber>1</PartNumber><ETag>{}</ETag></Part>"
    r = complete(f'<?xml version="1.0"?><!DOCTYPE CompleteMultipartUpload [<!ENTITY e \'{etag}\'>]>'
                 f"<CompleteMultipartUpload>{parts.format('&e;')}</CompleteMultipartUpload>")
    assert r.status == 400
    assert r.error_code == "MalformedXML"
    assert s3.get(bucket, key).status == 404

    # The upload survived the refusal and completes with a plain body.
    r = complete(f"<CompleteMultipartUpload>{parts.format(etag)}</CompleteMultipartUpload>")
    assert r.status == 200, r.body
    assert s3.get(bucket, key).body == b"only part"
