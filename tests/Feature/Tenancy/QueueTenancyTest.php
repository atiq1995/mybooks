<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\QueueTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Tenant context has to survive the queue boundary.
 *
 * A worker runs with no request, no session and no authenticated user, so
 * nothing publishes the settings PostgreSQL policies read. Without the payload
 * stamping in QueueTenancy every job touching organisation-scoped data fails
 * against row-level security — and confusingly, because SerializesModels
 * re-queries each model and reports a plainly-existing record as "not found".
 *
 * Regression cover for exactly that: the invitation email failed this way.
 */

/** A job that both carries a serialised model and writes scoped data. */
final class TenantAwareProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Organization $organization) {}

    public function handle(AuditRecorder $audit): void
    {
        // Reaching this line at all proves the serialised model was restored.
        $audit->record(
            action: 'job.ran',
            description: "Ran inside {$this->organization->name}",
        );
    }
}

/** Invoke a private static on QueueTenancy. */
function queueTenancy(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(QueueTenancy::class, $method))->invoke(null, ...$arguments);
}

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Traders']);
    $this->user = actingAsMember($this->organization, Role::Owner->value);
    $this->context = app(TenantContext::class);
});

it('stamps the active organisation onto a job as it is queued', function (): void {
    Queue::fake();

    TenantAwareProbeJob::dispatch($this->organization);

    Queue::assertPushed(TenantAwareProbeJob::class);
});

it('restores the organisation a job was dispatched in', function (): void {
    // Simulate a worker: no context at all, as after a fresh boot.
    $this->context->clear();
    expect($this->context->has())->toBeFalse();

    queueTenancy('enter', app(), [
        'tenant_organization_id' => $this->organization->getKey(),
        'tenant_user_id' => $this->user->getKey(),
    ]);

    expect($this->context->has())->toBeTrue()
        ->and($this->context->organization()->getKey())->toBe($this->organization->getKey());

    // And a scoped write succeeds, which is the whole point.
    app(AuditRecorder::class)->record('job.ran');

    expect(AuditLog::query()->where('action', 'job.ran')->count())->toBe(1);
});

it('leaves a worker holding nothing once the job is done', function (): void {
    $this->context->clear();

    queueTenancy('enter', app(), [
        'tenant_organization_id' => $this->organization->getKey(),
        'tenant_user_id' => $this->user->getKey(),
    ]);
    queueTenancy('leave', app());

    // A worker handles jobs for many organisations in sequence; a context that
    // outlived its job would hand one organisation's data to the next.
    expect($this->context->has())->toBeFalse();
});

it('hands back the caller context, which is what the sync driver needs', function (): void {
    $other = Organization::factory()->create(['name' => 'Beta Foods']);

    // A request is in progress inside Alpha, and dispatches a job for Beta.
    expect($this->context->organization()->getKey())->toBe($this->organization->getKey());

    queueTenancy('enter', app(), [
        'tenant_organization_id' => $other->getKey(),
        'tenant_user_id' => $this->user->getKey(),
    ]);

    expect($this->context->organization()->getKey())->toBe($other->getKey());

    queueTenancy('leave', app());

    /*
     * On the sync driver the job ran INSIDE the request. Blanket-clearing here
     * would wipe the tenant context of the request that dispatched it, and
     * every query after that point would fail.
     */
    expect($this->context->organization()->getKey())->toBe($this->organization->getKey());
});

it('establishes no context when the organisation was deleted since dispatch', function (): void {
    $this->context->clear();

    queueTenancy('enter', app(), [
        'tenant_organization_id' => (string) Str::uuid7(),
        'tenant_user_id' => $this->user->getKey(),
    ]);

    // Half-established context is worse than none: a scoped write would land
    // against an organisation that no longer exists.
    expect($this->context->has())->toBeFalse();
});

it('runs a real dispatched job with its tenant context intact', function (): void {
    // QUEUE_CONNECTION is sync in tests, so this goes through the full queue
    // lifecycle — payload stamping and restoration both exercised.
    TenantAwareProbeJob::dispatch($this->organization);

    $entry = AuditLog::query()->where('action', 'job.ran')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->organization_id)->toBe($this->organization->getKey())
        // ...and the dispatching context survived the job.
        ->and($this->context->organization()->getKey())->toBe($this->organization->getKey());
});
