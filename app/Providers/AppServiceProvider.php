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
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
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
        $this->configureParallelTesting();

        // Tenant context has to survive the queue boundary, or every job that
        // touches organisation-scoped data dies against row-level security.
        QueueTenancy::register($this->app);

        Date::use(Carbon::class);
    }

    /**
     * Give each parallel worker's database the two-role arrangement.
     *
     * `docker/postgres/init/03-test-database.sql` sets this up for
     * `my_books_test`, but Pest's parallel runner creates one database per
     * worker — `my_books_test_test_1` and so on — from template1, which
     * carries neither the grants nor the default privileges. The runtime role
     * then has no rights on any table, so every test that does `SET ROLE
     * my_books_app` to observe row-level security dies with "permission
     * denied" instead of testing anything.
     *
     * That is the worst possible failure for these particular tests: they are
     * the only ones that exercise the second isolation layer at all, since
     * the rest of the suite connects as the schema owner and bypasses RLS.
     * A permission error looks enough like a refusal to be mistaken for one.
     *
     * @see docs/adr/0002-multi-tenancy.md
     */
    private function configureParallelTesting(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        /*
         * Checked rather than applied blindly, and checked per test case
         * rather than once.
         *
         * Laravel only fires its own setUpTestDatabase hook for a database it
         * has just created, so a hook alone would work on CI and quietly not
         * work on any machine that already has worker databases from an
         * earlier run — the two would disagree about whether the second
         * isolation layer is tested at all. The probe is one catalogue lookup
         * and it self-heals, so both cases end up the same.
         *
         * The first test case in a worker runs before RefreshDatabase has
         * migrated, so there is nothing to grant on yet; leaving the flag
         * unset means the next one tries again.
         */
        $granted = false;

        ParallelTesting::setUpTestCase(function () use (&$granted): void {
            if ($granted || ! Schema::hasTable('organizations')) {
                return;
            }

            $granted = true;

            if (DB::scalar("SELECT has_table_privilege('my_books_app', 'organizations', 'SELECT')") === true) {
                return;
            }

            DB::unprepared(<<<'SQL'
                GRANT USAGE ON SCHEMA public TO my_books_app;

                GRANT SELECT, INSERT, UPDATE, DELETE
                    ON ALL TABLES IN SCHEMA public TO my_books_app;
                GRANT USAGE, SELECT
                    ON ALL SEQUENCES IN SCHEMA public TO my_books_app;

                ALTER DEFAULT PRIVILEGES FOR ROLE my_books IN SCHEMA public
                    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO my_books_app;
                ALTER DEFAULT PRIVILEGES FOR ROLE my_books IN SCHEMA public
                    GRANT USAGE, SELECT ON SEQUENCES TO my_books_app;

                -- The runtime role owns nothing and creates nothing. Stated
                -- here as well as in the container's init script, because
                -- this database was not built by that script.
                REVOKE CREATE ON SCHEMA public FROM my_books_app;
            SQL);
        });
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
