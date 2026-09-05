<?php

declare(strict_types=1);

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->user = actingAsMember($this->organization);
    $this->recorder = app(AuditRecorder::class);
});

it('records who did what, in which organisation', function (): void {
    $entry = $this->recorder->record(
        action: 'organization.updated',
        subject: $this->organization,
        description: 'Changed the trading name',
    );

    expect($entry->action)->toBe('organization.updated')
        ->and($entry->organization_id)->toBe($this->organization->getKey())
        ->and($entry->user_id)->toBe($this->user->getKey())
        ->and($entry->auditable_type)->toBe(Organization::class)
        ->and($entry->auditable_id)->toBe($this->organization->getKey())
        ->and($entry->description)->toBe('Changed the trading name');
});

it('denormalises the actor so the trail survives their deletion', function (): void {
    $entry = $this->recorder->record(action: 'user.signed_in');

    expect($entry->actor_name)->toBe($this->user->name)
        ->and($entry->actor_email)->toBe($this->user->email);

    $this->user->delete();

    // The record still says who did it.
    expect($entry->fresh()?->actor_name)->toBe($this->user->name);
});

it('redacts secrets while still recording that they changed', function (): void {
    $entry = $this->recorder->record(
        action: 'user.password_changed',
        old: ['password' => 'hunter2', 'name' => 'Ayesha'],
        new: [
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'api_token' => 'ghp_realtokenvalue',
            'name' => 'Ayesha Khan',
        ],
    );

    expect($entry->new_values)
        ->toHaveKey('password', '[redacted]')
        ->toHaveKey('password_confirmation', '[redacted]')
        ->toHaveKey('two_factor_secret', '[redacted]')
        ->toHaveKey('api_token', '[redacted]')
        // Non-sensitive values are preserved — the trail must remain useful.
        ->toHaveKey('name', 'Ayesha Khan');

    expect($entry->old_values)->toHaveKey('password', '[redacted]');

    // And nothing leaked into the stored JSON by another route.
    expect(json_encode($entry->new_values))->not->toContain('correct-horse')
        ->and(json_encode($entry->new_values))->not->toContain('ghp_realtokenvalue');
});

it('stores money as a decimal string, never a float', function (): void {
    $entry = $this->recorder->record(
        action: 'invoice.posted',
        amount: Money::of('117100.50', 'PKR'),
    );

    expect($entry->currency)->toBe('PKR')
        ->and((string) $entry->amount)->toBeDecimal('117100.5000');
});

it('diffs only what actually changed', function (): void {
    $this->organization->update(['name' => 'Renamed Traders']);

    $entry = $this->recorder->recordChange('organization.updated', $this->organization);

    expect($entry->new_values)->toHaveKey('name', 'Renamed Traders')
        // Untouched columns stay out of the trail — the question is
        // "what changed", not "what was there".
        ->and($entry->new_values)->not->toHaveKey('base_currency')
        ->and($entry->old_values)->toHaveKey('name');
});

it('commits with the change it describes, and rolls back with it', function (): void {
    $before = AuditLog::query()->count();

    try {
        DB::transaction(function (): void {
            $this->organization->update(['name' => 'Doomed Rename']);
            $this->recorder->record('organization.updated', $this->organization);

            throw new RuntimeException('something failed after the audit write');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Neither the change nor its audit row survived.
    expect(AuditLog::query()->count())->toBe($before)
        ->and($this->organization->fresh()?->name)->not->toBe('Doomed Rename');
});

it('refuses to be updated', function (): void {
    $entry = $this->recorder->record('invoice.posted');

    $entry->update(['action' => 'invoice.unposted']);
})->throws(LogicException::class, 'append-only');

it('refuses to be deleted', function (): void {
    $entry = $this->recorder->record('invoice.posted');

    $entry->delete();
})->throws(LogicException::class, 'append-only');

it('is scoped to its organisation like any other tenant-owned record', function (): void {
    $this->recorder->record('invoice.posted');

    $other = Organization::factory()->create();

    expect(AuditLog::query()->count())->toBe(1);

    asOrganization($other);

    expect(AuditLog::query()->count())->toBe(0);
});
