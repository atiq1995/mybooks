# syntax=docker/dockerfile:1.7
# ============================================================================
# My Books — application image
#
# Runtime is FrankenPHP: Caddy with PHP 8.4 embedded in a single process.
# One container serves HTTP, TLS and PHP, and also serves the built Vite
# assets, which removes the separate nginx service a php-fpm setup needs.
# Runs in CLASSIC mode (a fresh PHP worker per request) — Octane worker mode
# is a deliberate later optimisation, not a foundation-phase risk.
#
# Debian (trixie) base rather than Alpine: install-php-extensions finds
# prebuilt library packages on Debian, where on Alpine it pulled llvm and
# compiled from source for thirteen minutes. See docs/adr/0003-frankenphp-runtime.md
#
# Targets:
#   dev   — dependencies + tooling, source bind-mounted, assets from Vite HMR
#   prod  — source and compiled assets baked in, opcache preloaded
# ============================================================================

# ---------------------------------------------------------------------------
# base — PHP extensions shared by every stage
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-trixie AS base

# pdo_pgsql  PostgreSQL driver (Laravel's pgsql driver goes through PDO)
# redis      cache, session, queue driver
# gmp        brick/math's fastest backend — this is the money arithmetic path
# bcmath     brick/math fallback, and expected by most Laravel packages
# intl       currency, number and date formatting per organisation locale
# pcntl      required by queue workers and Horizon for signal handling
# zip        import/export archives
# sockets    required by Pest's browser-testing driver
# opcache    bytecode cache
#
# Deliberately NOT installed: gd (receipt thumbnails, Phase 5 — added when
# the feature that needs it arrives, not speculatively).
RUN install-php-extensions \
        pdo_pgsql \
        redis \
        gmp \
        bcmath \
        intl \
        pcntl \
        zip \
        sockets \
        opcache

# netcat-openbsd provides the `nc` the entrypoint uses to wait for services.
# Debian, unlike Alpine's busybox, does not ship one.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        netcat-openbsd \
        postgresql-client \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/php.ini   /usr/local/etc/php/conf.d/zz-my-books.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Replace the base image's health check.
#
# FrankenPHP's own probes Caddy's admin API on :2019, which our Caddyfile
# deliberately turns off (`admin off`) so it is never network-reachable. That
# check can therefore never pass, and the container reports unhealthy for ever
# — which an orchestrator answers by restart-looping a perfectly healthy
# application, and which makes `depends_on: service_healthy` hang.
#
# /up is Laravel's own liveness route: cheap, and it actually means "this
# process can serve traffic". Dependency health (Postgres, Redis, object
# storage) is a separate question, answered by /health and
# `php artisan my-books:health` — a container must not kill itself because
# the database blipped.
#
# start-period is generous: a first dev boot installs Composer dependencies
# before it can serve anything.
HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=3 \
    CMD curl -fsS http://localhost/up >/dev/null || exit 1

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# ---------------------------------------------------------------------------
# dev — full toolchain, source arrives via bind mount at runtime
#
# No Node here: the Vite dev server runs as its own container, so the PHP
# image stays a PHP image.
# ---------------------------------------------------------------------------
FROM base AS dev

# pcov alongside xdebug: pcov collects code coverage several times faster,
# xdebug is for actually stepping through a failing accounting calculation.
RUN install-php-extensions xdebug pcov

# Xdebug stays installed but OFF, because it roughly halves PHP throughput.
# Enable per command:  XDEBUG_MODE=debug php artisan ...
ENV XDEBUG_MODE=off \
    PCOV_ENABLED=0

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------------------
# assets — compile the React/Inertia frontend
# ---------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY tsconfig.json vite.config.ts ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# vendor — production PHP dependencies only
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---------------------------------------------------------------------------
# prod — minimal runtime
# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

COPY docker/app/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --from=vendor  /app                  /app
COPY --from=assets  /app/public/build     /app/public/build

# The web server must not own application code — only the writable paths.
RUN groupadd -g 1000 app \
    && useradd -u 1000 -g app -m -s /bin/bash app \
    && chown -R app:app /app/storage /app/bootstrap/cache \
    && chmod -R 775     /app/storage /app/bootstrap/cache

USER app

# Inherits the liveness check from `base` on purpose — a shorter start period
# is the only difference worth having here, since production images arrive with
# dependencies already installed.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/up >/dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
