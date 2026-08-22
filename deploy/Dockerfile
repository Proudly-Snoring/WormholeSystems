# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: PHP dependencies
# ---------------------------------------------------------------------------

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-ansi \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: frontend build
# ---------------------------------------------------------------------------
# Building the frontend needs both Node _and_ PHP because:
# - type-checks against generated route helpers (`artisan wayfinder:generate`),
# - EVE static data generation (`artisan generate:static-data`),
# - node/npm is in charge of building the frontend web files.
#
# Debian trixie ships PHP 8.4 in its own repositories, so this needs no third-party APT source.
# The extension set below is the one `composer check-platform-reqs` asks for.
# `pcntl` and `sockets` are compiled into Debian's php8.4-cli and have no package.

FROM debian:trixie-slim AS build

WORKDIR /app

# Nothing in this stage reads from either, but the framework still tries to resolve them before falling back to the model docblock only after the connection fails.
# Enforcing local hosts makes this lookup fail immediately and greatly speeds up the build.
ENV DB_HOST=127.0.0.1
ENV REDIS_HOST=127.0.0.1

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        libgcc-s1 \
        libstdc++6 \
        php8.4-cli \
        php8.4-bz2 \
        php8.4-curl \
        php8.4-intl \
        php8.4-mbstring \
        php8.4-mysql \
        php8.4-redis \
        php8.4-xml \
        php8.4-zip \
    && rm -rf /var/lib/apt/lists/*

# Debian's PHP packages carry no Node, so Node is grafted in from the official image.
# The major version tracks the one CI uses in .github/workflows/tests.yml, so a build that
# passes there is the same build that ships.
COPY --from=node:22 /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node:22 /usr/local/bin/node /usr/local/bin/node
# npm/npx are symlinks in node:22 and COPY dereferences those, which would land the launcher
# scripts in bin/ as regular files and break their relative require(). They are recreated instead.
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Import vendor and source files
COPY --from=vendor /app/vendor ./vendor
COPY . .

# The vendor stage runs before the application source exists, so optimising the autoloader there would only ever produce an empty class map.
# Building it here, with app/ and database/ in place, is what actually optimises the shipped image.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
    --ignore-platform-reqs \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --optimize

# A throwaway .env only boots the framework for the artisan commands below.
# It is never copied into the runtime image (a real one is supplied at container start).
RUN cp .env.example .env
RUN php artisan key:generate --ansi --force --no-interaction
RUN php artisan package:discover --ansi
RUN php artisan wayfinder:generate

# EVE static data export.
# Its JSON output is bundled into the frontend build by `generate:static-data` and isn't needed at runtime.
# So the raw download is discarded below.
RUN php artisan sde:download
RUN php artisan generate:static-data --no-interaction

# The browser-facing websocket settings and the application name are read by Vite and inlined
# into the JS bundle, so they are frozen at build time — the resulting image only ever talks to
# the host named here. The published image bakes mapper.prsn.online; the values it is built with
# are declared in .github/workflows/publish.yml.
# The defaults below are the ones a plain `docker build` should get, i.e. a localhost stack —
# the same one docker-compose.local.yml points at, hence the matching `local` app key.
ARG VITE_APP_NAME=WormholeSystems
ARG VITE_REVERB_APP_KEY=local
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8000
ARG VITE_REVERB_SCHEME=http

# Vite loads .env.production ahead of .env in production mode, which is how these override the
# ws.localhost placeholders the repository-root .env.example carries without editing that file.
# Removed again in the cleanup below, alongside .env.
RUN printf '%s\n' \
    "VITE_APP_NAME=${VITE_APP_NAME}" \
    "VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}" \
    "VITE_REVERB_HOST=${VITE_REVERB_HOST}" \
    "VITE_REVERB_PORT=${VITE_REVERB_PORT}" \
    "VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}" \
    > .env.production

# Build the frontend files.
# vite.config.ts runs `generate:static-data` again from its buildStart() hook.
# Skipped here: the data was already generated on the line above.
RUN npm ci && SKIP_STATIC_DATA_GENERATION=1 npm run build

# `sde:download` writes through the "local" disk, which config/filesystems.php roots at
# storage/app/private — not storage/app. Leaving it behind would ship the SDE zip plus its
# extracted tree in the image for no runtime benefit.
RUN rm -rf .env .env.production node_modules resources/static storage/app/private/sde tests

# Config/route caching is deliberately NOT done here (`artisan optimize`).
# It would bake build-time environment values into the image, silently shadowing the real values supplied at container start.
# It runs from entrypoint.sh instead, once the runtime .env is available.

# ---------------------------------------------------------------------------
# Stage 3: runtime
# ---------------------------------------------------------------------------
# nginx + php-fpm on plain Debian, serving HTTP on port 80 and nothing else.
#
# There is deliberately no EXPOSE and no TLS here: this image is only ever reached from inside
# the compose network. Certificates, the public hostname and the websocket route belong to the
# optional proxy — cf. deploy/docker-compose.proxy.yml and deploy/files/Caddyfile.

FROM debian:trixie-slim AS runtime

WORKDIR /app

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        nginx \
        php8.4-fpm \
        php8.4-cli \
        php8.4-bz2 \
        php8.4-curl \
        php8.4-intl \
        php8.4-mbstring \
        php8.4-mysql \
        php8.4-opcache \
        php8.4-redis \
        php8.4-xml \
        php8.4-zip \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Copy the static files from deploy/files/
# php.ini goes into both SAPI directories:
# - the FPM one covers the web requests,
# - the CLI one covers the entrypoint's artisan commands and the queue/scheduler/reverb/listener roles
COPY deploy/files/php.ini /etc/php/8.4/fpm/conf.d/99-wormholesystems.ini
COPY deploy/files/php.ini /etc/php/8.4/cli/conf.d/99-wormholesystems.ini
COPY deploy/files/php-fpm.conf /etc/php/8.4/fpm/pool.d/www.conf
COPY deploy/files/nginx.conf /etc/nginx/conf.d/app.conf
COPY deploy/files/run-web.sh /usr/local/bin/run-web.sh
COPY deploy/files/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/run-web.sh

# Debian ships a `default_server` vhost on :80. We will replace it with our own.
RUN rm -f /etc/nginx/sites-enabled/default
RUN mkdir -p /run/php

# nginx logs are redirected via a symlink to stdout/stderr so the docker logs catch them.
# NB: Access logging is off by default in files/nginx.conf.
RUN ln -sf /dev/stderr /var/log/nginx/error.log
RUN ln -sf /dev/stdout /var/log/nginx/access.log

# Copy the built application
COPY --from=build /app /app

# Prepare application directories
RUN mkdir -p /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache /app/storage/logs /app/bootstrap/cache
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# The below env are frozen into the image rather than left in deploy/.env: the websocket host is
# already baked in via VITE_REVERB_* above and can't be repointed at runtime, so a differing
# APP_URL here would always be a misconfiguration.
#
# Defaults below are the ones a plain `docker build` should get, i.e. a localhost stack — the
# same one docker-compose.local.yml points at. Building a production image requires to set these ARGs
# (for example, in a github action).

ARG APP_URL=http://localhost:8000
ARG DISCORD_CALLBACK=http://localhost:8000/discord/callback
ARG EVE_CALLBACK=http://localhost:8000/eve/callback
ARG SESSION_SECURE_COOKIE=false
# Same default as the build stage above, so the key the server checks always matches the one the JS bundle was built with.
ARG VITE_REVERB_APP_KEY=local

ENV APP_URL=${APP_URL}
ENV DISCORD_CALLBACK=${DISCORD_CALLBACK}
ENV EVE_CALLBACK=${EVE_CALLBACK}
ENV REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV SESSION_SECURE_COOKIE=${SESSION_SECURE_COOKIE}

# "web" is a sentinel, not a program: entrypoint.sh recognises it as the web role — the one that
# migrates and warms caches — and replaces it with run-web.sh. Every other service in the stack
# overrides this with its own `php artisan ...` command.
CMD ["web"]
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
