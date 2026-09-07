<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Organizations\Models\Organization;
use App\Http\Middleware\EstablishTenantContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Carries tenant context across the queue boundary.
 *
 * A queued job runs in a worker process with no request, no session and no
 * authenticated user — so nothing has published `app.organization_id` or
 * `app.user_id` to PostgreSQL. Row-level security therefore returns ZERO rows
 * for everything, and the failure is spectacularly confusing: Laravel's
 * SerializesModels re-queries each serialised model when the job runs, so the
 * job dies with "No query results for model [Organization]" — a model that
 * plainly exists.
 *
 * Every job that touches organisation-scoped data would hit this: invoice
 * PDFs, statement emails, recurring invoices, reminders. So it is solved once,
 * here, for all of them:
 *
 *   - on dispatch, the active organisation and user are stamped into the payload
 *   - before the job runs, they are restored
 *   - after it finishes, succeeds or fails, the PREVIOUS context is put back
 *
 * Restoring rather than clearing matters in both directions. In a worker there
 * is no previous context, so this is equivalent to clearing — which it must be,
 * because a worker handles jobs for many organisations in sequence and a
 * context that outlived its job would hand one organisation's data to the next.
 * On the `sync` driver the job runs INSIDE the caller, so blanket-clearing
 * would wipe the tenant context of the request that dispatched it and every
 * query after that point would fail.
 *
 * @see EstablishTenantContext for the request-side equivalent
 * @see SECURITY.md section 3
 */
final class QueueTenancy
{
    private const string ORGANIZATION_KEY = 'tenant_organization_id';

    private const string USER_KEY = 'tenant_user_id';

    /**
     * Contexts to put back, innermost last.
     *
     * A stack rather than a single value because jobs can dispatch jobs, and
     * on the sync driver those run nested inside one another.
     *
     * @var list<Organization|null>
     */
    private static array $stack = [];

    public static function register(Application $app): void
    {
        /*
         * Stamp the current context onto every job as it is queued. Runs in
         * the dispatching process, where the context still exists.
         */
        Queue::createPayloadUsing(static function () use ($app): array {
            $context = $app->make(TenantContext::class);

            return [
                self::ORGANIZATION_KEY => $context->id(),
                self::USER_KEY => Auth::id(),
            ];
        });

        Queue::before(static function (JobProcessing $event) use ($app): void {
            /** @var array<string, mixed> $payload */
            $payload = $event->job->payload();

            self::enter($app, $payload);
        });

        // Both, because a job either finishes or fails, and either way the
        // context it ran in has to be handed back.
        Queue::after(static fn (JobProcessed $event) => self::leave($app));
        Queue::failing(static fn (JobFailed $event) => self::leave($app));

        /*
         * Between polls a worker holds nothing. This is the hard reset that
         * catches anything an exception outside the normal lifecycle left
         * behind — and it is safe here precisely because a worker looping for
         * work has no caller whose context could be destroyed.
         */
        Queue::looping(static function () use ($app): void {
            self::$stack = [];
            self::reset($app);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function enter(Application $app, array $payload): void
    {
        $context = $app->make(TenantContext::class);

        // Remember what to hand back, then start from nothing.
        self::$stack[] = $context->organizationOrNull();

        $organizationId = $payload[self::ORGANIZATION_KEY] ?? null;
        $userId = $payload[self::USER_KEY] ?? null;

        $context->clear();
        self::publishUser(is_string($userId) ? $userId : null);

        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        /*
         * Publish the organisation id to PostgreSQL FIRST, then load the model.
         *
         * Order matters: the RLS policy on `organizations` admits a row when
         * `id = app_current_organization_id()`, so the setting has to be in
         * place before the query that reads it — otherwise the lookup that
         * establishes the context is itself blocked by the context being
         * missing.
         */
        self::publishOrganization($organizationId);

        $organization = Organization::query()->find($organizationId);

        if ($organization instanceof Organization) {
            // Also applies the application-layer scope, and re-publishes the
            // same id harmlessly.
            $context->set($organization);

            return;
        }

        // Deleted between dispatch and execution. Leave no context at all
        // rather than a half-established one.
        $context->clear();
        self::publishOrganization(null);
    }

    private static function leave(Application $app): void
    {
        $previous = self::$stack === [] ? null : array_pop(self::$stack);

        $context = $app->make(TenantContext::class);

        if ($previous instanceof Organization) {
            $context->set($previous);
            self::publishUser(Auth::id() === null ? null : (string) Auth::id());

            return;
        }

        self::reset($app);
    }

    private static function reset(Application $app): void
    {
        $app->make(TenantContext::class)->clear();
        self::publishUser(null);
        self::publishOrganization(null);
    }

    private static function publishUser(?string $userId): void
    {
        DB::statement("select set_config('app.user_id', ?, false)", [$userId ?? '']);
    }

    private static function publishOrganization(?string $organizationId): void
    {
        DB::statement(
            "select set_config('app.organization_id', ?, false)",
            [$organizationId ?? ''],
        );
    }
}
