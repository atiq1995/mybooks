# Architecture decision records

One file per decision that had a real alternative. Each records the context,
the choice, what was rejected, and why — so a future reader can tell a
deliberate decision from an accident of history, and knows what would have to
change for the decision to be revisited.

Format: `NNNN-short-title.md`. Never edit a decided ADR; supersede it with a
new one that links back.

| #                                       | Decision                                                        | Status   |
| --------------------------------------- | --------------------------------------------------------------- | -------- |
| [0001](0001-laravel-inertia-stack.md)   | Laravel + Inertia/React as the stack                            | Accepted |
| [0002](0002-two-layer-multi-tenancy.md) | Tenant isolation in two layers: Eloquent scope + PostgreSQL RLS | Accepted |
| [0003](0003-frankenphp-runtime.md)      | FrankenPHP as the runtime, classic mode                         | Accepted |
| [0004](0004-money-representation.md)    | `numeric(19,4)` + Brick\Money; floats prohibited                | Accepted |
