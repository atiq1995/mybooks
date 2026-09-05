<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;

/**
 * The application-layer half of tenant isolation.
 *
 * AuditLog is the organisation-owned model under test because it exists in
 * Phase 0. Every later organisation-owned model inherits the same behaviour
 * from the same trait, and gets the same assertions in its own suite.
 */
beforeEach(function (): void {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha']);
    $this->beta = Organization::factory()->create(['name' => 'Beta']);

    // Seed one row per organisation through the real path: runAs() sets the
    // context, and the trait fills organization_id. organization_id is not
    // fillable on organisation-owned models — a caller cannot name the owner.
    $context = app(TenantContext::class);

    $context->runAs($this->alpha, fn () => AuditLog::query()->create([
        'action' => 'alpha.event',
        'actor_type' => 'system',
        'channel' => 'console',
    ]));

    $context->runAs($this->beta, fn () => AuditLog::query()->create([
        'action' => 'beta.event',
        'actor_type' => 'system',
        'channel' => 'console',
    ]));
});

it('refuses to query an organisation-owned model with no tenant context', function (): void {
    app(TenantContext::class)->clear();

    AuditLog::query()->count();
})->throws(MissingTenantContext::class);

it('constrains every query to the active organisation', function (): void {
    asOrganization($this->alpha);

    $rows = AuditLog::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->action)->toBe('alpha.event');
});

it('cannot find another organisation\'s row even by primary key', function (): void {
    $betaRow = app(TenantContext::class)->runUnscoped(
        fn () => AuditLog::query()->where('action', 'beta.event')->firstOrFail(),
    );

    asOrganization($this->alpha);

    expect(AuditLog::query()->find($betaRow->getKey()))->toBeNull();
});

it('sees everything inside an explicitly unscoped block, and nothing after it', function (): void {
    asOrganization($this->alpha);

    $all = app(TenantContext::class)->runUnscoped(fn () => AuditLog::query()->count());
    $scoped = AuditLog::query()->count();

    expect($all)->toBe(2)
        ->and($scoped)->toBe(1);
});

it('fills organization_id automatically on create', function (): void {
    asOrganization($this->beta);

    $row = AuditLog::query()->create([
        'action' => 'created.in.context',
        'actor_type' => 'system',
        'channel' => 'console',
    ]);

    expect($row->organization_id)->toBe($this->beta->getKey());
});

it('refuses to create an organisation-owned row unscoped without an explicit owner', function (): void {
    app(TenantContext::class)->clear();

    app(TenantContext::class)->runUnscoped(function (): void {
        AuditLog::query()->create([
            'action' => 'orphan',
            'actor_type' => 'system',
            'channel' => 'console',
        ]);
    });
})->throws(RuntimeException::class, 'explicit organization_id');

it('treats organization_id as immutable', function (): void {
    asOrganization($this->alpha);

    $row = AuditLog::query()->firstOrFail();
    $row->organization_id = $this->beta->getKey();
    $row->save();
})->throws(RuntimeException::class, 'organization_id is immutable');

it('restores the previous context after runAs, even when the callback throws', function (): void {
    asOrganization($this->alpha);
    $context = app(TenantContext::class);

    try {
        $context->runAs($this->beta, function (): void {
            expect(AuditLog::query()->first()?->action)->toBe('beta.event');
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($context->organization()->getKey())->toBe($this->alpha->getKey())
        ->and(AuditLog::query()->first()?->action)->toBe('alpha.event');
});
