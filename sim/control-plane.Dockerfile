# syntax=docker/dockerfile:1.7
# Kiln control plane image for the local simulation (also a starting point for production).
#   build context:            ../control-plane
#   additional context "sim": ./sim   (entrypoint)
# One image, three roles: web (FrankenPHP, runs migrations), horizon, reverb.

ARG PHP_IMAGE=dunglas/frankenphp:1.12.7-php8.4-trixie

# --- base: PHP runtime + extensions ---------------------------------------------------
FROM ${PHP_IMAGE} AS base
RUN install-php-extensions pdo_pgsql redis pcntl intl zip bcmath gmp sockets opcache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'memory_limit=256M\nopcache.enable_cli=0\nopcache.validate_timestamps=0\nexpose_php=Off\n' > "$PHP_INI_DIR/conf.d/zz-kiln.ini"
WORKDIR /app

# --- vendor: composer deps (no dev) -----------------------------------------------------
FROM base AS vendor
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# --- assets: frontend build with bun ----------------------------------------------------
FROM oven/bun:1.4.2 AS assets
WORKDIR /app
COPY package.json bun.lock ./
RUN --mount=type=cache,target=/root/.bun/install/cache bun install --frozen-lockfile
COPY . .
# ziggy-js is resolved from the Composer package (tsconfig path alias -> vendor/tightenco/ziggy).
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN bun run build

# --- app ------------------------------------------------------------------------------
FROM base AS app
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=":8080" \
    APP_ENV=production \
    LOG_CHANNEL=stderr
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN rm -f bootstrap/cache/*.php \
 && composer dump-autoload --optimize --no-dev --no-interaction \
 && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/kiln /kiln/ca \
 && chown -R www-data:www-data storage bootstrap/cache
COPY --from=sim control-plane/entrypoint.sh /usr/local/bin/kiln-entrypoint
RUN chmod +x /usr/local/bin/kiln-entrypoint
EXPOSE 8080
ENTRYPOINT ["kiln-entrypoint"]
CMD ["web"]
