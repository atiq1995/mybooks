# 0001 — Laravel + Inertia/React as the stack

**Status:** Accepted · 2026-09-04

## Context

The initial architecture proposal recommended a TypeScript monorepo
(NestJS + Vite SPA + Drizzle). The product owner overrode this: an experienced
Laravel developer will own and review the system long-term, and Laravel was
mandated as a deliberate architectural decision.

## Decision

- **Laravel 13** on **PHP 8.4** as the single application, organised as a
  modular monolith with domain directories under `app/Domain/`.
- **Inertia + React + TypeScript** for the interface. Pages are React; routing,
  authorisation and validation stay in Laravel. No separate SPA, no duplicated
  API client, no second auth system.
- **PostgreSQL, Redis, MinIO**, all in Docker. No hosted dependency.
- **Pest 5** for tests, against real PostgreSQL.

## Rejected

- _Node/NestJS + SPA._ A better fit on paper for an API-first product, but the
  maintainer's expertise is the dominant long-term risk factor, and a stack
  the owner's team cannot review is not maintainable however elegant.
- _Blade-only._ Would not deliver the dense, keyboard-driven interface an
  accounting product needs.
- _Microservices._ A distributed ledger transaction is a corrupted ledger.

## Consequences

- Types cross the PHP/TS boundary by **generation**
  (`spatie/laravel-typescript-transformer`), never by hand.
- The frontend has no business logic. The line-item editor shows a subtotal
  for responsiveness; the server returns the figures that post.
- A public `/api/v1` is served by the same Actions the Inertia controllers
  call, so the browser has no privileged path the API lacks.
