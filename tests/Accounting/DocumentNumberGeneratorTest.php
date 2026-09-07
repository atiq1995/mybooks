<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| Gap-free numbering
|---------------------------------------------------------------------------
|
| A locked row, not a PostgreSQL sequence. Sequences are non-transactional: a
| rolled-back transaction still consumes its number and leaves a gap, and many
| tax authorities treat a gap in invoice numbering as evidence of a deleted
| invoice. These tests exist to stop anybody "optimising" this back into a
| sequence.
|
| @see ACCOUNTING_RULES.md §9
*/

beforeEach(function (): void {
    $this->organization = asOrganization(Organization::factory()->create());
    $this->numbers = app(DocumentNumberGenerator::class);
});

/** The generator must run inside a transaction: the lock is what makes it safe. */
function nextNumber(string $type = 'invoice', ?Carbon $date = null): string
{
    return DB::transaction(fn (): string => app(DocumentNumberGenerator::class)->next($type, $date));
}

it('issues consecutive numbers with no gaps', function (): void {
    $issued = collect(range(1, 12))->map(fn (): string => nextNumber())->all();

    expect($issued[0])->toBe('INV-000001');
    expect($issued[11])->toBe('INV-000012');

    // The property that matters: every number between the first and the last
    // was issued exactly once.
    expect(array_unique($issued))->toHaveCount(12);
});

it('creates the sequence on first use with a prefix suited to the document', function (): void {
    expect(nextNumber('invoice'))->toBe('INV-000001');
    expect(nextNumber('bill'))->toBe('BILL-000001');
    expect(nextNumber('journal'))->toBe('JE-000001');
    expect(nextNumber('payment_received'))->toBe('RCPT-000001');
    expect(nextNumber('credit_note'))->toBe('CN-000001');

    // An unknown type still gets something readable rather than failing.
    expect(nextNumber('delivery_challan'))->toBe('DEL-000001');
});

it('counts each document type separately', function (): void {
    nextNumber('invoice');
    nextNumber('invoice');

    expect(nextNumber('bill'))->toBe('BILL-000001');
    expect(nextNumber('invoice'))->toBe('INV-000003');
});

it('counts each organisation separately', function (): void {
    expect(nextNumber())->toBe('INV-000001');
    expect(nextNumber())->toBe('INV-000002');

    $other = Organization::factory()->create();

    $forOther = app(TenantContext::class)->runAs($other, fn (): string => nextNumber());

    expect($forOther)->toBe('INV-000001');

    // And the first organisation carries on where it left off.
    expect(nextNumber())->toBe('INV-000003');
});

it('does not consume a number when the transaction rolls back', function (): void {
    expect(nextNumber())->toBe('INV-000001');

    try {
        DB::transaction(function (): void {
            app(DocumentNumberGenerator::class)->next('invoice');

            throw new RuntimeException('something went wrong downstream');
        });
    } catch (RuntimeException) {
        // Expected. The point is what happens to the counter.
    }

    // A PostgreSQL sequence would return INV-000003 here, leaving 000002 as a
    // gap that looks like a deleted invoice.
    expect(nextNumber())->toBe('INV-000002');
});

it('restarts a yearly counter when the year turns, and not before', function (): void {
    DB::table('document_sequences')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->organization->getKey(),
        'document_type' => 'invoice',
        'prefix' => 'INV-',
        'next_number' => 7,
        'padding' => 6,
        'reset_policy' => 'yearly',
        'period_year' => 2026,
        'period_month' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(nextNumber('invoice', Carbon::parse('2026-11-30')))->toBe('INV-000007');
    expect(nextNumber('invoice', Carbon::parse('2027-01-01')))->toBe('INV-000001');
    expect(nextNumber('invoice', Carbon::parse('2027-02-01')))->toBe('INV-000002');
});

it('restarts a monthly counter when the month turns', function (): void {
    DB::table('document_sequences')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->organization->getKey(),
        'document_type' => 'invoice',
        'prefix' => 'INV-',
        'next_number' => 4,
        'padding' => 6,
        'reset_policy' => 'monthly',
        'period_year' => 2026,
        'period_month' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(nextNumber('invoice', Carbon::parse('2026-08-31')))->toBe('INV-000004');
    expect(nextNumber('invoice', Carbon::parse('2026-09-01')))->toBe('INV-000001');
});

it('pads to the configured width', function (): void {
    DB::table('document_sequences')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->organization->getKey(),
        'document_type' => 'invoice',
        'prefix' => 'MB/2026/',
        'next_number' => 42,
        'padding' => 3,
        'reset_policy' => 'never',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(nextNumber())->toBe('MB/2026/042');
});
