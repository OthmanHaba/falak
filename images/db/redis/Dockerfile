# syntax=docker/dockerfile:1.7
# Falak Redis image: the official image (pinned by digest in images/db/versions.json) plus falak-db (entrypoint,
# healthcheck and RDB backups; docs/DB_IMAGES.md). The password comes from FALAK_DB_PASSWORD_FILE and lands in the
# ACL file as a SHA-256 hash.
#
#   images/db/build.sh redis 8

ARG GO_IMAGE=golang:1.27-trixie
ARG BASE_IMAGE=redis:8

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
LABEL org.opencontainers.image.title="falak-redis" \
      org.opencontainers.image.description="Redis ${ENGINE_VERSION} for Falak managed databases (official image + falak-db)" \
      org.opencontainers.image.version="${ENGINE_VERSION}-${FALAK_VERSION}" \
      org.opencontainers.image.licenses="LicenseRef-Redis AND Apache-2.0" \
      sh.falak.db.engine="redis"
# Security updates published after the pinned base (the weekly rebuild picks them up).
RUN apt-get update \
 && apt-get upgrade -y --no-install-recommends \
 && rm -rf /var/lib/apt/lists/*
COPY --from=falak-db /falak-db /usr/local/bin/falak-db
ENV FALAK_DB_ENGINE=redis
# The official entrypoint drops to the redis user after preparing /data.
HEALTHCHECK --interval=10s --timeout=10s --start-period=30s --retries=3 CMD ["falak-db", "health"]
ENTRYPOINT ["falak-db", "init"]
CMD []
