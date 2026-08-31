#!/usr/bin/env bash
#
# Builds the tarball the Nextcloud app store expects and signs it.
#
# The store is picky in ways GitHub's own release tarballs are not: exactly one
# top level folder named after the app id, appinfo/info.xml inside it, no .git
# anywhere. A github.com/.../archive/v1.5.0.tar.gz has the version in the folder
# name and is rejected — hence this script.
#
# The app already lives in s3_api/, so this only has to prune the repository
# scaffolding (README, walgit test rig, LICENSE) from around it while keeping
# CHANGELOG.md, which the store parses out of the archive.
#
# Usage:
#   ./build-release.sh            build and sign
#   ./build-release.sh --no-sign  build only (no certificate needed)

set -euo pipefail

APP_ID="s3_api"
CERT_DIR="${HOME}/.nextcloud/certificates"
BUILD_DIR="build"
SIGN=1

[[ "${1:-}" == "--no-sign" ]] && SIGN=0

cd "$(dirname "$0")"

VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "${APP_ID}/appinfo/info.xml")"
if [[ -z "${VERSION}" ]]; then
	echo "could not read <version> from ${APP_ID}/appinfo/info.xml" >&2
	exit 1
fi

echo "==> ${APP_ID} ${VERSION}"

# The changelog version must match info.xml, otherwise the store silently
# imports an empty changelog.
if ! grep -qE "^## ${VERSION//./\\.} " CHANGELOG.md; then
	echo "CHANGELOG.md has no '## ${VERSION}' entry" >&2
	exit 1
fi

rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"

# Screenshots are fetched by URL from the repository, so they would only add
# weight to every installation. img/app.svg stays: it is the app icon.
rsync -a \
	--exclude '.git/' \
	--exclude 'img/[0-9]-*.png' \
	"${APP_ID}/" "${BUILD_DIR}/${APP_ID}/"

# The store reads the changelog from inside the archive.
cp CHANGELOG.md LICENSE "${BUILD_DIR}/${APP_ID}/"

TARBALL="${BUILD_DIR}/${APP_ID}-${VERSION}.tar.gz"
tar -czf "${TARBALL}" -C "${BUILD_DIR}" "${APP_ID}"

echo "==> verifying archive layout"
# One listing, reused: `tar | grep -q` exits early and makes pipefail fire on
# tar's SIGPIPE, which looks exactly like a failed check.
LISTING="$(tar -tzf "${TARBALL}")"

TOP_LEVEL="$(cut -d/ -f1 <<<"${LISTING}" | sort -u)"
if [[ "${TOP_LEVEL}" != "${APP_ID}" ]]; then
	echo "archive must contain exactly one top level folder '${APP_ID}', got: ${TOP_LEVEL}" >&2
	exit 1
fi
for required in "${APP_ID}/appinfo/info.xml" "${APP_ID}/CHANGELOG.md"; do
	if ! grep -qx "${required}" <<<"${LISTING}"; then
		echo "${required} missing from archive" >&2
		exit 1
	fi
done
if grep -q '/\.git/' <<<"${LISTING}"; then
	echo "archive contains .git — the store rejects that" >&2
	exit 1
fi

echo "    $(du -h "${TARBALL}" | cut -f1)  $(wc -l <<<"${LISTING}") entries"

if [[ "${SIGN}" -eq 1 ]]; then
	KEY="${CERT_DIR}/${APP_ID}.key"
	if [[ ! -f "${KEY}" ]]; then
		echo
		echo "no signing key at ${KEY}" >&2
		echo "run with --no-sign, or see README for how to obtain a certificate" >&2
		exit 1
	fi

	echo "==> signature (paste into the app store upload form)"
	openssl dgst -sha512 -sign "${KEY}" "${TARBALL}" | openssl base64 -A
	echo
fi

echo
echo "==> done: ${TARBALL}"
echo "    upload it somewhere stable (a GitHub release asset works) and"
echo "    submit the URL at https://apps.nextcloud.com/developer/apps/releases/new"
