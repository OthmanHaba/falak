# syntax=docker/dockerfile:1.7
# Kiln shared builder (`kiln-builder serve`) with the toolchains its native mode shells out to
# (git, PHP 8.4 + Composer, Node 22, Bun). Multi-arch: linux/amd64, linux/arm64.
#
#   docker buildx build -f deploy/builder.Dockerfile --build-arg KILN_VERSION=v1.2.3 \
#     --platform linux/amd64,linux/arm64 -t ghcr.io/othmanhaba/kiln-builder:v1.2.3 .
#
# Build context: the REPOSITORY ROOT. Proven in sim/builder. Docker-mode builds need a `builder`
# server (this container has no Docker daemon/BuildKit).

ARG GO_IMAGE=golang:1.25-trixie

FROM --platform=$BUILDPLATFORM ${GO_IMAGE} AS binary
ARG KILN_VERSION=dev
ARG TARGETARCH
WORKDIR /src/agent
COPY agent/go.mod agent/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY agent/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    make "bin/kiln-builder-linux-${TARGETARCH}" VERSION="${KILN_VERSION}" \
 && cp "bin/kiln-builder-linux-${TARGETARCH}" /kiln-builder

FROM php:8.4-cli-trixie
ARG KILN_VERSION=dev
ARG NODE_VERSION=22.20.0
LABEL org.opencontainers.image.title="kiln-builder" \
      org.opencontainers.image.description="Kiln shared build worker (kiln-builder serve) with PHP/Composer/Node/Bun toolchains" \
      org.opencontainers.image.version="${KILN_VERSION}"
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
COPY --from=oven/bun:1.4.2 /usr/local/bin/bun /usr/local/bin/bun
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip ca-certificates curl xz-utils openssh-client \
 && install-php-extensions intl zip bcmath pcntl gd exif \
 && curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-$(dpkg --print-architecture | sed 's/amd64/x64/').tar.xz" \
    | tar -xJ -C /usr/local --strip-components=1 \
 && rm -rf /var/lib/apt/lists/* /tmp/*
COPY --from=binary /kiln-builder /usr/local/bin/kiln-builder
COPY --chmod=0755 deploy/builder/entrypoint.sh /usr/local/bin/kiln-builder-entrypoint
ENV KILN_BUILDER_DIR=/var/lib/kiln-builder \
    COMPOSER_ALLOW_SUPERUSER=1
VOLUME ["/var/lib/kiln-builder"]
ENTRYPOINT ["kiln-builder-entrypoint"]
CMD ["serve"]
