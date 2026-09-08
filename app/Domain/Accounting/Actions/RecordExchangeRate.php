<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\ExchangeRate;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record what a currency pair was worth on a day.
 *
 * Correcting a rate is an UPDATE rather than a second row, because one rate
 * per pair per day is what makes conversion deterministic — two rows for one
 * day would let two people get different answers from the same invoice.
 *
 * Changing a rate does not restate anything already posted: every journal
 * line stored its base amount at posting time, and that figure is never
 * recomputed. The correction affects what happens next, which is why the
 * audit entry records both the old value and the new one.
 *
 * @see ACCOUNTING_RULES.md §8
 */
final readonly class RecordExchangeRate
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        string $from,
        string $to,
        string $rate,
        Carbon $effectiveOn,
        string $source = 'manual',
        ?User $actor = null,
    ): ExchangeRate {
        return DB::transaction(function () use ($from, $to, $rate, $effectiveOn, $source, $actor): ExchangeRate {
            $existing = ExchangeRate::query()
                ->where('from_currency', $from)
                ->where('to_currency', $to)
                ->whereDate('effective_on', $effectiveOn->toDateString())
                ->first();

            if ($existing !== null) {
                $previous = $existing->rate;

                $existing->forceFill(['rate' => $rate, 'source' => $source])->save();

                if ($existing->wasChanged()) {
                    $this->audit->record(
                        action: 'accounting.rate_corrected',
                        subject: $existing,
                        description: sprintf(
                            'Corrected %s→%s on %s from %s to %s',
                            $from,
                            $to,
                            $effectiveOn->toDateString(),
                            $previous,
                            $rate,
                        ),
                        old: ['rate' => $previous],
                        new: ['rate' => $rate, 'source' => $source],
                        actor: $actor,
                    );
                }

                return $existing;
            }

            $created = new ExchangeRate;

            $created->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->tenant->organization()->id,
                'from_currency' => $from,
                'to_currency' => $to,
                'rate' => $rate,
                'effective_on' => $effectiveOn->toDateString(),
                'source' => $source,
            ])->save();

            $this->audit->record(
                action: 'accounting.rate_recorded',
                subject: $created,
                description: "1 {$from} = {$rate} {$to} on {$effectiveOn->toDateString()}",
                new: [
                    'from_currency' => $from,
                    'to_currency' => $to,
                    'rate' => $rate,
                    'effective_on' => $effectiveOn->toDateString(),
                    'source' => $source,
                ],
                actor: $actor,
            );

            return $created;
        });
    }
}
