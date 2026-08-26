# walgit binary

`walgit` built from [tobi/walgit](https://github.com/tobi/walgit) at commit
`a71c592`, used to exercise this app end to end with real git.

Linux x86-64, dynamically linked against glibc 2.36 (built on Debian bookworm),
stripped.

```
./bin/walgit --version
```

## Why it is patched

Upstream does not compile as of `a71c592`. `walgit-build.patch` carries the two
fixes, both in `walgit-server`:

1. **`tempfile` was declared only under `[dev-dependencies]`** but is used in
   `src/lfs.rs`, outside of tests, so a non-test build failed to resolve it.

2. **`into_make_service_with_connect_info::<SocketAddr>()` no longer type-checks
   against axum 0.8.9.** axum implements `Connected<IncomingStream<TcpListener>>`
   for `SocketAddr`, and walgit serves through its own `NodelayListener` (it sets
   `TCP_NODELAY`, which matters for receive-pack's many small writes). The orphan
   rule bars implementing the foreign trait on the foreign type, so the patch
   adds a local `PeerAddr` newtype, implements `Connected` for it, and has
   `request_peer()` read either shape — the TLS path still yields
   `ConnectInfo<SocketAddr>`.

Neither is related to this app; both are upstream build breaks against current
crate versions.

## Rebuilding

```bash
git clone https://github.com/tobi/walgit && cd walgit
git checkout a71c592
git apply /path/to/bin/walgit-build.patch

podman run --rm -v "$PWD:/w:z" -w /w docker.io/library/rust:1.97-bookworm sh -c '
  apt-get update -qq && apt-get install -y -qq protobuf-compiler pkg-config libssl-dev cmake
  cargo build --release -p walgit-cli'

strip target/release/walgit
```

The unstripped binary is ~360 MB; stripping brings it to ~56 MB.

## Running it against this app

See the `walgit.toml` example in the top-level README. In short:

```bash
export AWS_ACCESS_KEY_ID=<access-key>
export AWS_SECRET_ACCESS_KEY=<secret-key>
./bin/walgit --config walgit.toml config check
./bin/walgit --config walgit.toml serve
```

Then push and clone against `http://127.0.0.1:8080/<owner>/<repo>.git`.
