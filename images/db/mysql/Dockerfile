# syntax=docker/dockerfile:1.7
# Falak MySQL image: the official image (Oracle Linux 9, pinned by digest in images/db/versions.json), Percona
# XtraBackup of the matching major (8.0 -> percona-xtrabackup-80, 8.4 -> percona-xtrabackup-84) for physical backups,
# and falak-db (entrypoint, healthcheck, backup and binlog helper; docs/DB_IMAGES.md).
#
#   images/db/build.sh mysql 8.4
#
# XtraBackup comes from Percona's repository as a pinned RPM checked against a SHA-256 from versions.json. Its Perl
# dependencies (perl(DBD::mysql), perl(Digest::MD5)) serve legacy scripts Falak never runs, so they are skipped;
# the build fails if a shared library of the binaries is missing.

ARG GO_IMAGE=golang:1.25-trixie
ARG BASE_IMAGE=mysql:8.4

FROM --platform=$BUILDPLATFORM ${GO_IMAGE} AS falak-db
ARG FALAK_VERSION=dev
ARG TARGETARCH
WORKDIR /src/agent
COPY agent/go.mod agent/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY agent/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 GOOS=linux GOARCH="${TARGETARCH}" go build -trimpath \
      -ldflags "-s -w -buildid= -X github.com/OthmanHaba/falak/agent/internal/version.Version=${FALAK_VERSION}" \
      -o /falak-db ./cmd/falak-db

FROM ${BASE_IMAGE}
ARG FALAK_VERSION=dev
ARG ENGINE_VERSION
ARG TARGETARCH
ARG XB_REPO
ARG XB_PACKAGE
ARG XB_VERSION
ARG XB_SHA256_AMD64
ARG XB_SHA256_ARM64
LABEL org.opencontainers.image.title="falak-mysql" \
      org.opencontainers.image.description="MySQL ${ENGINE_VERSION} for Falak managed databases (official image + Percona XtraBackup + falak-db)" \
      org.opencontainers.image.version="${ENGINE_VERSION}-${FALAK_VERSION}" \
      org.opencontainers.image.licenses="GPL-2.0-only AND Apache-2.0" \
      sh.falak.db.engine="mysql" \
      sh.falak.db.xtrabackup="${XB_PACKAGE}-${XB_VERSION}"
RUN set -eu; \
    case "${TARGETARCH}" in \
      amd64) arch=x86_64;  sum="${XB_SHA256_AMD64}" ;; \
      arm64) arch=aarch64; sum="${XB_SHA256_ARM64}" ;; \
      *) echo "unsupported arch ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    rpm="${XB_PACKAGE}-${XB_VERSION}.el9.${arch}.rpm"; \
    curl -fsSL -o "/tmp/${rpm}" "https://repo.percona.com/${XB_REPO}/yum/release/9/RPMS/${arch}/${rpm}"; \
    echo "${sum}  /tmp/${rpm}" | sha256sum -c -; \
    # MySQL Shell (with its own Python and packages) is not used by the entrypoint or by Falak. \
    microdnf remove -y mysql-shell; \
    microdnf upgrade -y; \
    microdnf install -y libev lz4 zstd rsync procps-ng cpio; \
    rpm -i --nodeps "/tmp/${rpm}"; \
    rm -f "/tmp/${rpm}"; \
    # mysqlbinlog (binlog replay for PITR) is not in the server-minimal package: take it from the client RPM of the \
    # exact server version, checked against MySQL's signing key that the base image ships. \
    series="${MYSQL_VERSION%.*}"; series="${series%.*}"; \
    client="mysql-community-client-${MYSQL_VERSION}.${arch}.rpm"; \
    curl -fsSL -o "/tmp/${client}" "https://repo.mysql.com/yum/mysql-${series}-community/el/9/${arch}/${client}"; \
    rpm --import /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql; \
    rpm -K "/tmp/${client}" | grep -q 'digests signatures OK'; \
    (cd / && rpm2cpio "/tmp/${client}" | cpio -idm --quiet ./usr/bin/mysqlbinlog); \
    rm -f "/tmp/${client}"; \
    microdnf remove -y cpio; \
    microdnf clean all; \
    for b in xtrabackup xbstream mysqlbinlog; do \
      if ldd "$(command -v "$b")" | grep -q 'not found'; then ldd "$(command -v "$b")"; exit 1; fi; \
    done; \
    xtrabackup --version
COPY --from=falak-db /falak-db /usr/local/bin/falak-db
ENV FALAK_DB_ENGINE=mysql \
    FALAK_DB_SPOOL=/var/lib/falak/db/spool
# The official entrypoint drops to the mysql user after preparing the data directory.
HEALTHCHECK --interval=10s --timeout=10s --start-period=180s --retries=3 CMD ["falak-db", "health"]
ENTRYPOINT ["falak-db", "init"]
CMD []
