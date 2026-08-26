# walgit binary

`walgit` built from [tobi/walgit](https://github.com/tobi/walgit) at commit
`113a0b2`, used to exercise this app end to end with real git.

Linux x86-64, dynamically linked against glibc 2.36 (built on Debian bookworm),
stripped, ~54 MB. Not tracked in git — see below to rebuild it.

```
./bin/walgit --version
```

## Building

Upstream builds unmodified as of `113a0b2`:

```bash
git clone https://github.com/tobi/walgit && cd walgit

podman run --rm -v "$PWD:/w:z" -w /w docker.io/library/rust:1.97-bookworm sh -c '
  apt-get update -qq && apt-get install -y -qq protobuf-compiler pkg-config libssl-dev cmake
  cargo build --release -p walgit-cli'

strip target/release/walgit
```

Stripping matters: the binary is ~360 MB with debug info, ~54 MB without.

An earlier checkout (`a71c592`) did not compile — `tempfile` was used in
`walgit-server/src/lfs.rs` but declared only under `[dev-dependencies]`, and a
custom `NodelayListener` had no `Connected` impl, so
`into_make_service_with_connect_info::<SocketAddr>()` failed to type-check.
Both were fixed upstream in PR #4; the listener now applies `TCP_NODELAY` via
`Listener::tap_io`, which keeps the connection a plain `TcpStream` so axum's
blanket impl supplies the peer address. No patching needed.

## Running it against this app

See the `walgit.toml` example in the top-level README. In short:

```bash
export AWS_ACCESS_KEY_ID=<access-key>
export AWS_SECRET_ACCESS_KEY=<secret-key>
./bin/walgit --config walgit.toml config check
./bin/walgit --config walgit.toml serve
```

Then push and clone against `http://127.0.0.1:8080/<owner>/<repo>.git`.

Verified with this binary against a Nextcloud 34 instance: a 2000-commit
synthetic repository (`walgit synth --size m`, 20 branches, 50 tags) pushes,
clones back into an empty cache with all 71 refs bit-identical, passes
`git fsck --strict`, and accepts an incremental push afterwards.
