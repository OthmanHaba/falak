# syntax=docker/dockerfile:1.7
# Falak PostgreSQL image: the official image (pinned by digest in images/db/versions.json) plus falak-db, which is
# the entrypoint, the healthcheck, and the backup / WAL-archiving helper (docs/DB_IMAGES.md).
#
#   images/db/build.sh postgres 17          # or, by hand, from the REPOSITORY ROOT:
#   docker buildx build -f images/db/postgres/Dockerfile --build-arg BASE_IMAGE=postgres:17-bookworm@sha256:... .
#
# Physical backups and PITR use pg_basebackup and postgres' own archive/restore commands (falak-db wal-push /
# wal-fetch), so no wal-g.

ARG GO_IMAGE=golang:1.25-trixie
ARG BASE_IMAGE=postgres:17-bookworm

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
LABEL org.opencontainers.image.title="falak-postgres" \
      org.opencontainers.image.description="PostgreSQL ${ENGINE_VERSION} for Falak managed databases (official image + falak-db)" \
      org.opencontainers.image.version="${ENGINE_VERSION}-${FALAK_VERSION}" \
      org.opencontainers.image.licenses="PostgreSQL AND Apache-2.0" \
      sh.falak.db.engine="postgres"
# Security updates published after the pinned base (the weekly rebuild picks them up).
RUN apt-get update \
 && apt-get upgrade -y --no-install-recommends \
 && rm -rf /var/lib/apt/lists/*
COPY --from=falak-db /falak-db /usr/local/bin/falak-db
ENV FALAK_DB_ENGINE=postgres \
    FALAK_DB_SPOOL=/var/lib/falak/db/spool
# The official entrypoint drops to the postgres user after preparing the data directory, so the container starts as
# root like the base image does.
HEALTHCHECK --interval=10s --timeout=10s --start-period=120s --retries=3 CMD ["falak-db", "health"]
STOPSIGNAL SIGINT
ENTRYPOINT ["falak-db", "init"]
CMD []
