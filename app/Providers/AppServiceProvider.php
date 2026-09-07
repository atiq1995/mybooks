<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\QueueTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * One tenant context per request, job or command.
         *
         * A singleton rather than static state, so a queued job gets a fresh
         * one and cannot silently inherit whichever organisation happened to
         * be active last.
         */
        $this->app->singleton(TenantContext::class, function (): TenantContext {
            $context = new TenantContext;

            /*
             * Keep PostgreSQL's view of the active organisation in step with
             * the application's, automatically. Whenever tenant context
             * changes — a request resolving it, an Action switching to a
             * newly-created organisation, a job running as a tenant — the
             * row-level security setting follows.
             *
             * Without this the two isolation layers diverge mid-request and
             * writes get refused by a policy for reasons that look nothing
             * like the cause.
             */
            $context->publishUsing(function (?string $organizationId): void {
                // Bound, never interpolated.
                DB::statement(
                    "select set_config('app.organization_id', ?, false)",
                    [$organizationId ?? ''],
                );
            });

            return $context;
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDatabase();
        $this->configurePasswords();

        // Tenant context has to survive the queue boundary, or every job that
        // touches organisation-scoped data dies against row-level security.
        QueueTenancy::register($this->app);

        Date::use(Carbon::class);
    }

    private function configureModels(): void
    {
        /*
         * Fail loudly on a missing relationship rather than silently issuing
         * N queries in a loop. In an accounting UI the N is the number of
         * invoice lines on screen, and it will be noticed in production long
         * before it is noticed here.
         *
         * Not enforced in production: a lazy-load in an unexercised code path
         * should degrade performance, not throw at a customer.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        /*
         * Accessing an attribute that was never selected returns null by
         * default, which in an accounting context can silently mean "zero".
         * Make it an error everywhere except production.
         */
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Guard against mass assignment by default; models opt in via
        // $fillable. `organization_id`, totals and status never do.
        Model::unguard(false);

        /*
         * Models live under app/Domain/<Domain>/Models, but factories all live
         * flat in database/factories — so the default guess
         * (Database\Factories\Domain\Sales\Models\InvoiceFactory) is wrong for
         * every domain model. Resolve by class basename instead.
         */
        Factory::guessFactoryNamesUsing(
            /** @return class-string<Factory<Model>> */
            static function (string $modelName): string {
                /** @var class-string<Factory<Model>> $factory */
                $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

                return $factory;
            },
        );
    }

    private function configureDatabase(): void
    {
        /*
         * A query that takes longer than a second in an accounting UI is a
         * report that should have been paginated, or an index that does not
         * exist. Surface it in development rather than discovering it when
         * the ledger has a million lines.
         */
        if (! $this->app->isProduction()) {
            DB::whenQueryingForLongerThan(1000, function ($connection, $event): void {
                logger()->warning('Slow query', [
                    'connection' => $connection->getName(),
                    'time_ms' => $event->time,
                    // The SQL, never the bindings: bindings carry customer
                    // names and amounts. SECURITY.md §8.
                    'sql' => $event->sql,
                ]);
            });
        }

        // Wrapping a destructive command in a confirmation is not enough when
        // the command is `migrate:fresh` against production.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            /*
             * Twelve characters and a breach check, rather than a symbol
             * requirement. Composition rules push people towards
             * "Password1!" — length and known-breach screening are what
             * actually resist credential stuffing.
             */
            $rule = Password::min(12);

            return $this->app->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });
    }
}
