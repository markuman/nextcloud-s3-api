#!/usr/bin/env bash
#
# Integration tests against a throwaway Nextcloud.
#
# Starts the official Nextcloud image (SQLite, no external services) with
# s3_api mounted read-only into custom_apps, enables the app and runs the
# pytest suite in tests/ against it. Buckets and keys are created by the suite
# itself through the app's settings API, so nothing here knows about them.
#
# Usage:
#   tests/run.sh                      all tests
#   tests/run.sh -k conditional -x    any pytest arguments
#   tests/run.sh test_delete.py       paths are relative to tests/
#
# Environment:
#   NC_VERSION   image tag prefix, default 34   (-> nextcloud:34-apache)
#                "master" builds an image from Nextcloud's daily master tarball
#                (the next major), see nextcloud-master.Containerfile
#   REFRESH=1    with NC_VERSION=master: fetch a new daily even if one is built
#   NC_PORT      host port, default 18080
#   KEEP=1       leave the container running, and reuse it on the next run
#   ENGINE       podman (default) or docker
#   GITS3        path to gits3.py for the gits3 tests, default ../gits3srv/gits3.py

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NC_VERSION="${NC_VERSION:-34}"
NC_PORT="${NC_PORT:-18080}"
ENGINE="${ENGINE:-podman}"
NAME="s3api-test-nc${NC_VERSION}"
IMAGE="docker.io/library/nextcloud:${NC_VERSION}-apache"
ADMIN_PASSWORD="s3api-test-admin"
BOB_PASSWORD="s3api-test-bob"

occ() {
	"${ENGINE}" exec -u www-data "${NAME}" php occ "$@"
}

teardown() {
	local status=$?
	if [[ ${status} -ne 0 ]]; then
		echo "==> last nextcloud.log entries" >&2
		"${ENGINE}" exec "${NAME}" sh -c 'tail -n 20 /var/www/html/data/nextcloud.log 2>/dev/null' >&2 || true
	fi
	if [[ "${KEEP:-0}" != "1" ]]; then
		"${ENGINE}" rm -f "${NAME}" >/dev/null 2>&1 || true
	else
		echo "==> container ${NAME} left running on http://127.0.0.1:${NC_PORT}" >&2
	fi
	exit "${status}"
}

running() {
	[[ "$("${ENGINE}" inspect -f '{{.State.Running}}' "${NAME}" 2>/dev/null)" == "true" ]]
}

# The next major has no image yet: build one from the daily tarball, on top of
# the newest stable image for its PHP and extensions.
build_master_image() {
	local context
	IMAGE="localhost/s3api-test-nextcloud:master"
	if [[ "${REFRESH:-0}" != "1" ]] && "${ENGINE}" image exists "${IMAGE}" 2>/dev/null; then
		return
	fi
	context="$(mktemp -d)"
	echo "==> fetching nextcloud daily master"
	curl -fsSL https://download.nextcloud.com/server/daily/latest-master.tar.bz2 | tar -xj -C "${context}"
	echo "==> building ${IMAGE} ($(sed -n "s/^\$OC_VersionString = '\(.*\)';/\1/p" "${context}/nextcloud/version.php"))"
	"${ENGINE}" build -q -t "${IMAGE}" -f "${ROOT}/tests/nextcloud-master.Containerfile" "${context}" >/dev/null
	rm -rf "${context}"
}

if [[ "${NC_VERSION}" == "master" ]]; then
	build_master_image
fi

if [[ "${KEEP:-0}" == "1" ]] && running; then
	echo "==> reusing ${NAME}"
else
	"${ENGINE}" rm -f "${NAME}" >/dev/null 2>&1 || true
	echo "==> starting ${IMAGE} on 127.0.0.1:${NC_PORT}"
	"${ENGINE}" run -d --name "${NAME}" \
		-p "127.0.0.1:${NC_PORT}:80" \
		-e SQLITE_DATABASE=nextcloud \
		-e NEXTCLOUD_ADMIN_USER=admin \
		-e NEXTCLOUD_ADMIN_PASSWORD="${ADMIN_PASSWORD}" \
		-e NEXTCLOUD_TRUSTED_DOMAINS="127.0.0.1 localhost" \
		-v "${ROOT}/s3_api:/var/www/html/custom_apps/s3_api:ro,z" \
		"${IMAGE}" >/dev/null
fi
trap teardown EXIT

echo "==> waiting for the installation"
for _ in $(seq 1 180); do
	if curl -fsS "http://127.0.0.1:${NC_PORT}/status.php" 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	sleep 1
done
curl -fsS "http://127.0.0.1:${NC_PORT}/status.php" | grep -q '"installed":true' || {
	echo "nextcloud did not come up" >&2
	exit 1
}

echo "==> configuring"
if ! out="$(occ app:enable s3_api 2>&1)"; then
	echo "${out}" >&2
	exit 1
fi
# Pretty URLs, so /apps/s3_api/s3 works as documented and not only behind
# /index.php.
occ config:system:set htaccess.RewriteBase --value=/ >/dev/null
occ maintenance:update:htaccess >/dev/null
# Brute force protection would throttle the deliberately failing requests.
occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false >/dev/null
if ! occ user:info bob >/dev/null 2>&1; then
	"${ENGINE}" exec -u www-data -e OC_PASS="${BOB_PASSWORD}" "${NAME}" \
		php occ user:add --password-from-env bob >/dev/null
fi

# The web server may still serve a cached app list in which s3_api is
# disabled (APCu is per process, occ runs in another). Wait for the route,
# and restart the container if the cache does not let go.
app_ready() {
	local code
	code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${NC_PORT}/index.php/apps/s3_api/s3")"
	[[ "${code}" == "403" ]]
}
ready=0
for attempt in 1 2; do
	for _ in $(seq 1 20); do
		if app_ready; then
			ready=1
			break 2
		fi
		sleep 1
	done
	if [[ ${attempt} -eq 1 ]]; then
		echo "==> app route not live yet, restarting the container"
		"${ENGINE}" restart "${NAME}" >/dev/null
	fi
done
if [[ ${ready} -ne 1 ]]; then
	echo "s3_api route never came up" >&2
	exit 1
fi

export S3T_BASE_URL="http://127.0.0.1:${NC_PORT}"
export S3T_ADMIN_USER=admin
export S3T_ADMIN_PASSWORD="${ADMIN_PASSWORD}"
export S3T_BOB_USER=bob
export S3T_BOB_PASSWORD="${BOB_PASSWORD}"
export S3T_CONTAINER="${NAME}"
export S3T_ENGINE="${ENGINE}"
export S3T_GITS3="${GITS3:-${ROOT}/../gits3srv/gits3.py}"

echo "==> pytest"
cd "${ROOT}/tests"
uv run --no-project --with pytest --with boto3 \
	python -m pytest "$@"
