# 0003 — FrankenPHP as the runtime, classic mode

**Status:** Accepted · 2026-09-05

## Context

The stack requires "Nginx or Caddy" in front of PHP. The conventional Laravel
deployment is nginx + php-fpm as two containers with two configurations.

## Decision

**FrankenPHP** (`dunglas/frankenphp`, Debian trixie base): Caddy with PHP 8.4
embedded in one process. One container serves HTTP, TLS, static assets and
PHP. Runs in **classic mode** — a fresh PHP request lifecycle per request.

Octane worker mode is explicitly deferred to Phase 13.

## Rejected

- _nginx + php-fpm._ More familiar, but two containers, two configs, and a
  separate TLS story. FrankenPHP is now Laravel's documented production
  runtime and removes a service.
- _Octane worker mode now._ Faster, but any request-scoped state that leaks
  (a static, a singleton holding a tenant) becomes a cross-tenant bug. Not a
  risk to take before the application is well understood.
- _Alpine base._ `install-php-extensions` on Alpine pulled llvm and compiled
  for thirteen minutes, then failed. Debian has prebuilt library packages.

## Consequences

- `SERVER_NAME=books.example.com` gives automatic TLS; `:80` for local.
- The `TenantContext` singleton is safe because it is per-request in classic
  mode. Enabling worker mode later requires auditing every singleton and
  resetting tenant context between requests.
- Production must pin the image digest.
