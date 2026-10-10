# syntax=docker/dockerfile:1.7
# Falak MariaDB image: the official image (pinned by digest in images/db/versions.json; mariadb-backup is already in
# it) plus falak-db (entrypoint, healthcheck, backup and binlog helper; docs/DB_IMAGES.md).
#
#   images/db/build.sh mariadb 11.4

ARG GO_IMAGE=golang:1.27-trixie
ARG BASE_IMAGE=mariadb:11.4

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
LABEL org.opencontainers.image.title="falak-mariadb" \
      org.opencontainers.image.description="MariaDB ${ENGINE_VERSION} for Falak managed databases (official image + falak-db)" \
      org.opencontainers.image.version="${ENGINE_VERSION}-${FALAK_VERSION}" \
      org.opencontainers.image.licenses="GPL-2.0-only AND Apache-2.0" \
      sh.falak.db.engine="mariadb"
# Security updates published after the pinned base (the weekly rebuild picks them up).
RUN apt-get update \
 && apt-get upgrade -y --no-install-recommends \
 && rm -rf /var/lib/apt/lists/*
COPY --from=falak-db /falak-db /usr/local/bin/falak-db
ENV FALAK_DB_ENGINE=mariadb \
    FALAK_DB_SPOOL=/var/lib/falak/db/spool
# The official entrypoint drops to the mysql user after preparing the data directory.
HEALTHCHECK --interval=10s --timeout=10s --start-period=180s --retries=3 CMD ["falak-db", "health"]
ENTRYPOINT ["falak-db", "init"]
CMD []
