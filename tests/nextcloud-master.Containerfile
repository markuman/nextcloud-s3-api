# Nextcloud's daily master build on top of the newest stable image.
#
# There is no official image for an unreleased major, but the stable image
# already has the PHP version and extensions, and its entrypoint installs
# whatever lies in /usr/src/nextcloud. So: swap that tree for the daily
# tarball, keeping the image's own config snippets (custom_apps path, apcu,
# pretty URLs), which are not part of the tarball.
#
# Built by tests/run.sh for NC_VERSION=master; the build context is the
# extracted tarball's parent directory.
ARG BASE=docker.io/library/nextcloud:35-apache
FROM ${BASE}

RUN mkdir /tmp/image-config \
	&& cp /usr/src/nextcloud/config/*.config.php /usr/src/nextcloud/config/autoconfig.php \
		/usr/src/nextcloud/config/CAN_INSTALL /tmp/image-config/ \
	&& rm -rf /usr/src/nextcloud

COPY --chown=www-data:www-data nextcloud /usr/src/nextcloud

RUN cp /tmp/image-config/* /usr/src/nextcloud/config/ \
	&& chown www-data:www-data /usr/src/nextcloud/config/* \
	&& rm -rf /tmp/image-config
