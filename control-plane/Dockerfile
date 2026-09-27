# syntax=docker/dockerfile:1.7
# Kiln control plane — production image (multi-arch: linux/amd64, linux/arm64).
#
#   docker buildx build -f control-plane/Dockerfile --build-arg KILN_VERSION=v1.2.3 \
#     --platform linux/amd64,linux/arm64 -t ghcr.io/OWNER/kiln-control-plane:v1.2.3 .
#
# Build context: the REPOSITORY ROOT (the image bakes agent/CLI/builder binaries, the agent protocol
# schemas and the Grafana dashboards next to the Laravel app). Ignore rules: Dockerfile.dockerignore.
#
# One image, four roles (entrypoint argument): web (FrankenPHP; runs migrations when KILN_MIGRATE=1),
# horizon, reverb, scheduler. Runs as www-data. Proven topology: sim/compose.yml; production: deploy/compose.yml.

ARG PHP_IMAGE=dunglas/frankenphp:1.12.7-php8.4-trixie
ARG GO_IMAGE=golang:1.25-trixie

# --- binaries: kiln-agent / kiln / kiln-builder for linux amd64+arm64 (cross-compiled once) -------
FROM --platform=$BUILDPLATFORM ${GO_IMAGE} AS binaries
ARG KILN_VERSION=dev
WORKDIR /src/agent
COPY agent/go.mod agent/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY agent/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    make agent builder bin/kiln-linux-amd64 bin/kiln-linux-arm64 VERSION="${KILN_VERSION}" \
 && mkdir -p /out \
 && for f in kiln-agent-linux-amd64 kiln-agent-linux-arm64 kiln-builder-linux-amd64 kiln-builder-linux-arm64 kiln-linux-amd64 kiln-linux-arm64; do \
      cp "bin/$f" "/out/$f"; (cd /out && sha256sum "$f" > "$f.sha256"); \
    done

# --- base: PHP runtime + extensions -----------------------------------------------------------------
FROM ${PHP_IMAGE} AS base
RUN install-php-extensions pdo_pgsql redis pcntl intl zip bcmath gmp sockets opcache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/control-plane/php.ini "$PHP_INI_DIR/conf.d/zz-kiln.ini"
WORKDIR /app

# --- vendor: composer deps (no dev) -------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY control-plane/composer.json control-plane/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# --- assets: frontend build with bun --------------------------------------------------------------
FROM --platform=$BUILDPLATFORM oven/bun:1.4.2 AS assets
WORKDIR /app
COPY control-plane/package.json control-plane/bun.lock ./
RUN --mount=type=cache,target=/root/.bun/install/cache bun install --frozen-lockfile
COPY control-plane/ ./
# ziggy-js is resolved from the Composer package (tsconfig path alias -> vendor/tightenco/ziggy).
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN bun run build

# --- app ------------------------------------------------------------------------------------------------
FROM base AS app
ARG KILN_VERSION=dev
LABEL org.opencontainers.image.title="kiln-control-plane" \
      org.opencontainers.image.description="Kiln control plane (Laravel + FrankenPHP) with baked agent/CLI/builder binaries" \
      org.opencontainers.image.version="${KILN_VERSION}" \
      org.opencontainers.image.licenses="MIT"
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY control-plane/ ./
COPY --from=assets /app/public/build ./public/build
COPY --from=binaries /out/ /opt/kiln/bin/
COPY contracts/ /opt/kiln/contracts/
COPY observability/grafana/dashboards/ /opt/kiln/observability/grafana/dashboards/
COPY deploy/control-plane/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=0755 deploy/control-plane/entrypoint.sh /usr/local/bin/kiln-entrypoint
COPY --chmod=0755 deploy/control-plane/healthcheck.sh /usr/local/bin/kiln-healthcheck
RUN rm -f bootstrap/cache/*.php .env \
 && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --no-interaction \
 && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
 && rm /usr/bin/composer \
 && ln -sfn /app/storage/app/public /app/public/storage \
 && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views \
             storage/logs storage/kiln /kiln/ca \
 && echo "${KILN_VERSION}" > /opt/kiln/VERSION \
 && chown -R www-data:www-data storage bootstrap/cache /kiln/ca /data/caddy /config/caddy \
 # File capabilities are not needed (port 8080) and would break under no-new-privileges.
 && setcap -r /usr/local/bin/frankenphp
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    KILN_VERSION=${KILN_VERSION} \
    KILN_CA_PATH=/kiln/ca \
    KILN_AGENT_BINARIES_PATH=/opt/kiln/bin \
    KILN_BUILDER_BINARIES_PATH=/opt/kiln/bin \
    KILN_AGENT_SCHEMAS_PATH=/opt/kiln/contracts/agent-protocol \
    KILN_GRAFANA_DASHBOARDS_PATH=/opt/kiln/observability/grafana/dashboards
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=10s --start-period=60s --retries=6 CMD ["kiln-healthcheck"]
ENTRYPOINT ["kiln-entrypoint"]
CMD ["web"]
