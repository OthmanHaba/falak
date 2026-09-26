# Shared build host for the sim: kiln-builder + the toolchains its native/docker modes shell out to.
FROM php:8.4-cli-trixie
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
COPY --from=oven/bun:1.4.2 /usr/local/bin/bun /usr/local/bin/bun
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip ca-certificates curl xz-utils \
 && install-php-extensions intl zip bcmath pcntl \
 && curl -fsSL https://nodejs.org/dist/v22.20.0/node-v22.20.0-linux-$(dpkg --print-architecture | sed 's/amd64/x64/').tar.xz \
    | tar -xJ -C /usr/local --strip-components=1 \
 && rm -rf /var/lib/apt/lists/*
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
