<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap-free document numbering.
 *
 * A locked row, NOT a PostgreSQL sequence. Sequences are faster and would be
 * the obvious choice, but they are explicitly non-transactional: a rolled-back
 * transaction still consumes its number, leaving a gap. Many tax authorities
 * treat a gap in invoice numbering as evidence of a deleted invoice, so the
 * cost of `SELECT … FOR UPDATE` is the price of a defensible audit trail.
 *
 * Because the row is locked for the remainder of the transaction, concurrent
 * postings serialise on it. That is the intended behaviour — two invoices must
 * not receive the same number, and they must not skip one either.
 *
 * @see ACCOUNTING_RULES.md §9
 */
final readonly class DocumentNumberGenerator
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * Claim the next number for a document type.
     *
     * MUST be called inside a transaction: the row lock is what makes the
     * result unique, and it is released at commit.
     */
    public function next(string $documentType, ?Carbon $date = null): string
    {
        $date ??= Carbon::now();
        $organizationId = $this->tenant->organization()->getKey();

        /** @var object{id: string, prefix: string, next_number: int, padding: int, reset_policy: string, period_year: int|null, period_month: int|null}|null $sequence */
        $sequence = DB::table('document_sequences')
            ->where('organization_id', $organizationId)
            ->where('document_type', $documentType)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            $sequence = $this->create($documentType, $date);
        }

        $number = $sequence->next_number;
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        // A reset policy restarts the counter when the calendar moves on.
        if ($this->shouldReset($sequence, $year, $month)) {
            $number = 1;
        }

        DB::table('document_sequences')
            ->where('id', $sequence->id)
            ->update([
                'next_number' => $number + 1,
                'period_year' => $year,
                'period_month' => $month,
                'updated_at' => Carbon::now(),
            ]);

        return $sequence->prefix.str_pad(
            (string) $number,
            $sequence->padding,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * @param  object{reset_policy: string, period_year: int|null, period_month: int|null}  $sequence
     */
    private function shouldReset(object $sequence, int $year, int $month): bool
    {
        return match ($sequence->reset_policy) {
            'yearly' => $sequence->period_year !== null && $sequence->period_year !== $year,
            'monthly' => ($sequence->period_year !== null && $sequence->period_year !== $year)
                || ($sequence->period_month !== null && $sequence->period_month !== $month),
            default => false,
        };
    }

    /**
     * Create the sequence on first use, so an organisation does not need every
     * document type seeded up front.
     *
     * @return object{id: string, prefix: string, next_number: int, padding: int, reset_policy: string, period_year: int|null, period_month: int|null}
     */
    private function create(string $documentType, Carbon $date): object
    {
        $id = (string) Str::uuid7();

        DB::table('document_sequences')->insert([
            'id' => $id,
            'organization_id' => $this->tenant->organization()->getKey(),
            'document_type' => $documentType,
            'prefix' => self::defaultPrefix($documentType),
            'next_number' => 1,
            'padding' => 6,
            'reset_policy' => 'never',
            'period_year' => (int) $date->format('Y'),
            'period_month' => (int) $date->format('n'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        /** @var object{id: string, prefix: string, next_number: int, padding: int, reset_policy: string, period_year: int|null, period_month: int|null} $created */
        $created = DB::table('document_sequences')->where('id', $id)->lockForUpdate()->first();

        return $created;
    }

    /**
     * The prefix a sequence starts life with.
     *
     * A default only: the row is editable, so an organisation that numbers
     * its bills differently changes it once and keeps it. Every document type
     * is named explicitly rather than left to the fallback, because the
     * fallback takes the first three letters — which turns `purchase_order`
     * into PUR- and `vendor_credit` into VEN-, neither of which anybody would
     * choose.
     */
    private static function defaultPrefix(string $documentType): string
    {
        return match ($documentType) {
            'invoice' => 'INV-',
            'estimate' => 'EST-',
            'sales_order' => 'SAL-',
            'credit_note' => 'CN-',
            'payment_received' => 'RCPT-',

            'purchase_order' => 'PO-',
            'bill' => 'BILL-',
            'vendor_credit' => 'VCN-',
            'payment_made' => 'PAY-',

            'journal' => 'JE-',

            default => mb_strtoupper(mb_substr($documentType, 0, 3)).'-',
        };
    }
}
