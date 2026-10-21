"""A deliberately small SigV4 client for poking at the S3 endpoint.

boto3 hides exactly the things these tests need to control: which headers get
signed, the payload hash, the request date, malformed or tampered bodies. This
client signs the way gits3 does (path-style, `host` plus the `x-amz-*`
headers, literal payload hash) and exposes every knob as an argument.
"""
import datetime
import hashlib
import hmac
import http.client
import urllib.parse
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field

EMPTY_SHA256 = hashlib.sha256(b"").hexdigest()
NS = "{http://s3.amazonaws.com/doc/2006-03-01/}"


def quote(value, safe="-_.~"):
    return urllib.parse.quote(value, safe=safe)


def signing_key(secret, date, region):
    k = ("AWS4" + secret).encode()
    for part in (date, region, "s3", "aws4_request"):
        k = hmac.new(k, part.encode(), hashlib.sha256).digest()
    return k


@dataclass
class Response:
    status: int
    headers: dict
    body: bytes = b""
    raw_headers: list = field(default_factory=list)

    def header(self, name, default=None):
        return self.headers.get(name.lower(), default)

    @property
    def etag(self):
        return self.header("etag")

    def xml(self):
        return ET.fromstring(self.body)

    @property
    def error_code(self):
        """`<Code>` of an S3 error body, or None."""
        if not self.body:
            return None
        try:
            return self.xml().findtext("Code")
        except ET.ParseError:
            return None


class S3Client:
    def __init__(self, endpoint, access_key, secret_key, region="us-east-1"):
        u = urllib.parse.urlsplit(endpoint.rstrip("/"))
        self.scheme, self.host, self.base = u.scheme, u.netloc, u.path
        self.access_key, self.secret_key, self.region = access_key, secret_key, region

    # -- plumbing -----------------------------------------------------------

    def _connection(self):
        cls = http.client.HTTPSConnection if self.scheme == "https" else http.client.HTTPConnection
        return cls(self.host, timeout=120)

    def uri(self, bucket=None, key=None):
        path = self.base
        if bucket is not None:
            path += "/" + bucket
        if key is not None:
            path += "/" + key
        return quote(path, safe="/-_.~")

    @staticmethod
    def canonical_query(query):
        if not query:
            return ""
        items = query.items() if isinstance(query, dict) else query
        return "&".join(f"{quote(k)}={quote(v)}" for k, v in sorted(items))

    def sign(self, method, uri, cq, headers, payload_hash, amz_date, signed=None, secret=None, region=None):
        """Return the Authorization header value and the signature."""
        region = region or self.region
        lower = {k.lower(): str(v).strip() for k, v in headers.items()}
        # Like every SDK: host plus all x-amz-* headers. gits3 sends no other
        # x-amz-* header, so for it this is exactly its fixed list.
        signed = sorted(signed or ["host"] + [h for h in lower if h.startswith("x-amz-")])
        canonical_headers = "".join(f"{h}:{lower.get(h, '')}\n" for h in signed)
        creq = "\n".join([method, uri, cq, canonical_headers, ";".join(signed), payload_hash])
        scope = f"{amz_date[:8]}/{region}/s3/aws4_request"
        sts = "\n".join(["AWS4-HMAC-SHA256", amz_date, scope, hashlib.sha256(creq.encode()).hexdigest()])
        key = signing_key(secret or self.secret_key, amz_date[:8], region)
        sig = hmac.new(key, sts.encode(), hashlib.sha256).hexdigest()
        auth = (f"AWS4-HMAC-SHA256 Credential={self.access_key}/{scope}, "
                f"SignedHeaders={';'.join(signed)}, Signature={sig}")
        return auth, sig

    def request(self, method, bucket=None, key=None, body=b"", headers=None, query=None, *,
                payload_hash=None, amz_date=None, signed=None, secret=None, region=None,
                auth=True, raw_query=None, skip_content_length=False):
        """Send one signed request.

        `query` is a dict or list of pairs and is used both on the wire and in
        the signature. `raw_query` replaces the wire form only (to test e.g.
        valueless `?uploads`), while the canonical form still comes from
        `query`.
        """
        uri = self.uri(bucket, key)
        cq = self.canonical_query(query)
        amz_date = amz_date or datetime.datetime.now(datetime.UTC).strftime("%Y%m%dT%H%M%SZ")
        if payload_hash is None:
            payload_hash = hashlib.sha256(body).hexdigest()

        h = {"Host": self.host, "x-amz-date": amz_date, "x-amz-content-sha256": payload_hash}
        h.update(headers or {})
        if auth:
            h["Authorization"], _ = self.sign(method, uri, cq, h, payload_hash, amz_date,
                                              signed=signed, secret=secret, region=region)
        wire_query = raw_query if raw_query is not None else cq
        return self.send(method, uri + (f"?{wire_query}" if wire_query else ""), body, h,
                         skip_content_length=skip_content_length)

    def send(self, method, target, body, headers, skip_content_length=False):
        conn = self._connection()
        try:
            conn.putrequest(method, target, skip_host=True, skip_accept_encoding=True)
            for k, v in headers.items():
                conn.putheader(k, v)
            wants_length = body or (method in ("PUT", "POST") and not skip_content_length)
            if wants_length and not any(k.lower() == "content-length" for k in headers):
                conn.putheader("Content-Length", str(len(body)))
            conn.endheaders(body if body else None)
            resp = conn.getresponse()
            data = resp.read()
            raw = resp.getheaders()
            return Response(resp.status, {k.lower(): v for k, v in raw}, data, raw)
        finally:
            conn.close()

    # -- convenience ----------------------------------------------------------

    def put(self, bucket, key, body=b"", **kw):
        return self.request("PUT", bucket, key, body, **kw)

    def get(self, bucket, key, **kw):
        return self.request("GET", bucket, key, **kw)

    def head(self, bucket, key=None, **kw):
        return self.request("HEAD", bucket, key, **kw)

    def delete(self, bucket, key, **kw):
        return self.request("DELETE", bucket, key, **kw)

    def list_v2(self, bucket, **params):
        """One ListObjectsV2 page. Keyword names use `_` for `-`."""
        query = {"list-type": "2"}
        query.update({k.replace("_", "-"): str(v) for k, v in params.items()})
        return self.request("GET", bucket, query=query)

    def list_all(self, bucket, prefix="", **params):
        """Every key under `prefix`, following continuation tokens."""
        keys, token = [], None
        for _ in range(1000):
            p = dict(prefix=prefix, **params)
            if token:
                p["continuation_token"] = token
            r = self.list_v2(bucket, **p)
            assert r.status == 200, r.body
            root = r.xml()
            keys += [c.findtext(f"{NS}Key") for c in root.iter(f"{NS}Contents")]
            if root.findtext(f"{NS}IsTruncated") != "true":
                return keys
            token = root.findtext(f"{NS}NextContinuationToken")
            assert token, "IsTruncated without NextContinuationToken"
        raise AssertionError("listing did not terminate")

    # -- aws-chunked --------------------------------------------------------

    def put_chunked_signed(self, bucket, key, chunks, *, tamper=None, drop_final=False, headers=None):
        """PutObject with `STREAMING-AWS4-HMAC-SHA256-PAYLOAD`.

        `tamper(index, data) -> data` replaces a chunk's bytes after it has
        been signed; `drop_final` omits the terminating zero-length chunk.
        """
        payload_hash = "STREAMING-AWS4-HMAC-SHA256-PAYLOAD"
        amz_date = datetime.datetime.now(datetime.UTC).strftime("%Y%m%dT%H%M%SZ")
        uri = self.uri(bucket, key)
        decoded = sum(len(c) for c in chunks)

        h = {"Host": self.host, "x-amz-date": amz_date, "x-amz-content-sha256": payload_hash,
             "Content-Encoding": "aws-chunked", "x-amz-decoded-content-length": str(decoded)}
        h.update(headers or {})
        signed = ["content-encoding", "host", "x-amz-content-sha256", "x-amz-date",
                  "x-amz-decoded-content-length"]
        h["Authorization"], seed = self.sign("PUT", uri, "", h, payload_hash, amz_date, signed=signed)

        scope = f"{amz_date[:8]}/{self.region}/s3/aws4_request"
        key_bytes = signing_key(self.secret_key, amz_date[:8], self.region)
        prev = seed
        body = b""
        frames = list(chunks) + ([] if drop_final else [b""])
        for i, data in enumerate(frames):
            sts = "\n".join(["AWS4-HMAC-SHA256-PAYLOAD", amz_date, scope, prev, EMPTY_SHA256,
                             hashlib.sha256(data).hexdigest()])
            sig = hmac.new(key_bytes, sts.encode(), hashlib.sha256).hexdigest()
            prev = sig
            sent = tamper(i, data) if tamper else data
            body += f"{len(sent):x};chunk-signature={sig}\r\n".encode() + sent + b"\r\n"
        return self.send("PUT", uri, body, h)

    def put_chunked_unsigned(self, bucket, key, chunks, trailer=None, decoded=None, final=True):
        """PutObject with `STREAMING-UNSIGNED-PAYLOAD-TRAILER`, the framing the
        current AWS SDKs default to. `decoded` overrides the declared length,
        `final=False` leaves out the terminating zero-length chunk."""
        payload_hash = "STREAMING-UNSIGNED-PAYLOAD-TRAILER"
        if decoded is None:
            decoded = sum(len(c) for c in chunks)
        body = b"".join(f"{len(c):x}\r\n".encode() + c + b"\r\n" for c in chunks)
        if not final:
            return self.request("PUT", bucket, key, body, payload_hash=payload_hash,
                                headers={"Content-Encoding": "aws-chunked",
                                         "x-amz-decoded-content-length": str(decoded)})
        body += b"0\r\n"
        headers = {"Content-Encoding": "aws-chunked", "x-amz-decoded-content-length": str(decoded)}
        if trailer:
            name, value = trailer
            headers["x-amz-trailer"] = name
            body += f"{name}:{value}\r\n".encode()
        body += b"\r\n"
        return self.request("PUT", bucket, key, body, headers=headers, payload_hash=payload_hash)
