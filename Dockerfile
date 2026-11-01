# syntax=docker/dockerfile:1

# Base images are build arguments so a hardened drop-in replacement can be used
# without editing this file, for example the Aikido Images mirror:
#   --build-arg PHP_IMAGE=docker.aikido.io/<token>/dunglas/frankenphp:1-php8.4-bookworm
#   --build-arg NODE_IMAGE=docker.aikido.io/<token>/node:22-bookworm-slim
ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm
ARG NODE_IMAGE=node:22-bookworm-slim
ARG COMPOSER_IMAGE=composer:2

FROM ${NODE_IMAGE} AS node
FROM ${COMPOSER_IMAGE} AS composer

# ---------------------------------------------------------------------------
# Base: FrankenPHP + the PHP extensions the app needs + Node (Vite at build
# time, the Inertia SSR server at run time).
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

WORKDIR /app

RUN install-php-extensions bcmath gmp intl opcache pcntl pdo_mysql redis sockets zip

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-tandem.ini"

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# ---------------------------------------------------------------------------
# Build: production vendor/, client bundle, SSR bundle, Wayfinder types.
# ---------------------------------------------------------------------------
FROM base AS build

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi \
    && npm run build:ssr \
    && rm -rf node_modules

# ---------------------------------------------------------------------------
# Runtime: one image for every role (web, horizon, scheduler, reverb, ssr);
# the role is the container command.
# ---------------------------------------------------------------------------
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp \
    XDG_CONFIG_HOME=/app/storage/caddy/config \
    XDG_DATA_HOME=/app/storage/caddy/data

COPY --from=build --chown=www-data:www-data /app /app
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/tandem-entrypoint

RUN mkdir -p storage/caddy/config storage/caddy/data \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs storage/app/private storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/up") === false ? 1 : 0);'

ENTRYPOINT ["tandem-entrypoint"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--admin-port=2019", "--max-requests=500"]
