<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An estimate, a sales order, an invoice or a credit note.
 *
 * One model for four documents, because they differ in exactly two ways that
 * matter: whether issuing them posts, and what statuses they can reach. Both
 * live on {@see SalesDocumentType} and {@see SalesDocumentStatus} rather than
 * in four near-identical classes.
 *
 * Totals are stored, not derived. They were computed once by the tax engine
 * at the precision §5 demands, and re-deriving them later — from rates that
 * may since have changed — is how a document silently stops agreeing with the
 * journal entry it produced.
 *
 * @property string $id
 * @property string $organization_id
 * @property SalesDocumentType $type
 * @property string $number
 * @property string $contact_id
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property Carbon|null $expires_on
 * @property string|null $reference
 * @property string|null $converted_from_id
 * @property string|null $credits_document_id
 * @property SalesDocumentStatus $status
 * @property string $currency
 * @property string $exchange_rate
 * @property bool $prices_include_tax
 * @property string|null $discount_type
 * @property string|null $discount_value
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property string $subtotal_base
 * @property string $discount_total_base
 * @property string $tax_total_base
 * @property string $total_base
 * @property string $amount_paid
 * @property string $amount_credited
 * @property string|null $notes
 * @property string|null $terms
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $shipping_address
 * @property string|null $journal_entry_id
 * @property string|null $void_journal_entry_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $voided_at
 */
final class SalesDocument extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    /**
     * Only what a user may edit on a draft.
     *
     * Number, totals, status and the journal links are all absent: they are
     * decided by the Actions, and a mass-assigned total would be a figure
     * nobody computed.
     */
    protected $fillable = [
        'issue_date',
        'due_date',
        'expires_on',
        'reference',
        'notes',
        'terms',
        'discount_type',
        'discount_value',
        'prices_include_tax',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SalesDocumentType::class,
            'status' => SalesDocumentStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'expires_on' => 'date',
            'prices_include_tax' => 'boolean',
            // Decimal strings throughout. A float cast here would be the one
            // that quietly rounds a customer's balance.
            'exchange_rate' => 'string',
            'discount_value' => 'string',
            'subtotal' => 'string',
            'discount_total' => 'string',
            'tax_total' => 'string',
            'total' => 'string',
            'subtotal_base' => 'string',
            'discount_total_base' => 'string',
            'tax_total_base' => 'string',
            'total_base' => 'string',
            'amount_paid' => 'string',
            'amount_credited' => 'string',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * A posted document is immutable in every respect that matters.
     *
     * The columns carved out below are the settlement bookkeeping — how much
     * has been paid or credited, and the void trail. Everything else about an
     * issued document is history, and history that can be edited is not
     * evidence of anything.
     */
    protected static function booted(): void
    {
        self::updating(function (self $document): void {
            if (! $document->status->isIssued() && ! $document->isDirty('status')) {
                return;
            }

            $permitted = [
                'status',
                'amount_paid',
                'amount_credited',
                'journal_entry_id',
                'void_journal_entry_id',
                'issued_at',
                'issued_by',
                'voided_at',
                'voided_by',
                'due_date',
                'number',
                'updated_at',
            ];

            $forbidden = array_diff(array_keys($document->getDirty()), $permitted);

            if ($forbidden !== []) {
                throw new \RuntimeException(sprintf(
                    'Cannot change %s on issued %s %s. An issued document is corrected by '.
                    'a credit note or a void, never by editing — see ACCOUNTING_RULES.md §6.',
                    implode(', ', $forbidden),
                    $document->type->label(),
                    $document->number,
                ));
            }
        });

        self::deleting(function (self $document): void {
            if ($document->status->isIssued()) {
                throw new \RuntimeException(sprintf(
                    'Cannot delete issued %s %s. Void it instead, which posts a reversing '.
                    'entry and leaves both on the record.',
                    $document->type->label(),
                    $document->number,
                ));
            }
        });
    }

    /**
     * @return HasMany<SalesDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<SalesDocumentLineTax, $this>
     */
    public function lineTaxes(): HasMany
    {
        return $this->hasMany(SalesDocumentLineTax::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function voidJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'void_journal_entry_id');
    }

    /**
     * What this document was converted from — an estimate, for a sales order.
     *
     * @return BelongsTo<self, $this>
     */
    public function convertedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_from_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(self::class, 'converted_from_id');
    }

    /**
     * The invoice this credit note credits.
     *
     * @return BelongsTo<self, $this>
     */
    public function creditsDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credits_document_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credits_document_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * What is still owed, in document currency.
     *
     * Total less payments and credits. Never negative: an over-payment is an
     * advance held against the contact, not a negative invoice.
     */
    public function balanceDue(): string
    {
        $due = BigDecimal::of($this->total)
            ->minus(BigDecimal::of($this->amount_paid))
            ->minus(BigDecimal::of($this->amount_credited));

        return (string) ($due->isNegative() ? BigDecimal::zero() : $due)->toScale(4);
    }

    public function isSettled(): bool
    {
        return BigDecimal::of($this->balanceDue())->isZero();
    }

    /**
     * Whether this is past its due date and still owes something.
     *
     * Derived rather than stored, because "overdue" changes at midnight
     * without anything happening to the document — and a nightly job that
     * flipped a status column would be one more thing to go wrong.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if ($this->due_date === null || $this->isSettled() || ! $this->status->isOutstanding()) {
            return false;
        }

        return $this->due_date->isBefore(($asOf ?? Carbon::now())->startOfDay());
    }

    public function daysOverdue(?Carbon $asOf = null): int
    {
        if (! $this->isOverdue($asOf)) {
            return 0;
        }

        /** @var Carbon $due */
        $due = $this->due_date;

        return (int) $due->diffInDays(($asOf ?? Carbon::now())->startOfDay());
    }

    public function isForeignCurrency(): bool
    {
        return ! BigDecimal::of($this->exchange_rate)->isEqualTo(BigDecimal::one());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, SalesDocumentType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SalesDocumentStatus::Sent->value,
            SalesDocumentStatus::Open->value,
            SalesDocumentStatus::PartiallyPaid->value,
            SalesDocumentStatus::Overdue->value,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }
}
