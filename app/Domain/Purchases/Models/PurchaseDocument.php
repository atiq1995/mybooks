<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A purchase order, a bill or a vendor credit.
 *
 * Totals are stored, not derived — the same rule as on the sales side, for
 * the same reason: they were computed once by the tax engine at the precision
 * §5 demands, and re-deriving them from rates that may since have changed is
 * how a document stops agreeing with its journal entry.
 *
 * @property string $id
 * @property string $organization_id
 * @property PurchaseDocumentType $type
 * @property string $number
 * @property string $contact_id
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property Carbon|null $expires_on
 * @property string|null $vendor_reference
 * @property string|null $reference
 * @property string|null $converted_from_id
 * @property string|null $credits_document_id
 * @property PurchaseDocumentStatus $status
 * @property string $currency
 * @property string $exchange_rate
 * @property bool $prices_include_tax
 * @property string|null $discount_type
 * @property string|null $discount_value
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $tax_claimable_total
 * @property string $total
 * @property string $subtotal_base
 * @property string $discount_total_base
 * @property string $tax_total_base
 * @property string $tax_claimable_total_base
 * @property string $total_base
 * @property string $amount_paid
 * @property string $amount_credited
 * @property string|null $notes
 * @property string|null $terms
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $shipping_address
 * @property string|null $journal_entry_id
 * @property string|null $void_journal_entry_id
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $voided_at
 */
final class PurchaseDocument extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    /**
     * Only what a user may edit on a draft. Number, totals, status and the
     * journal links are decided by the Actions.
     */
    protected $fillable = [
        'issue_date',
        'due_date',
        'expires_on',
        'vendor_reference',
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
            'type' => PurchaseDocumentType::class,
            'status' => PurchaseDocumentStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'expires_on' => 'date',
            'prices_include_tax' => 'boolean',
            // Decimal strings throughout; a float cast here is the one that
            // quietly rounds what we owe.
            'exchange_rate' => 'string',
            'discount_value' => 'string',
            'subtotal' => 'string',
            'discount_total' => 'string',
            'tax_total' => 'string',
            'tax_claimable_total' => 'string',
            'total' => 'string',
            'subtotal_base' => 'string',
            'discount_total_base' => 'string',
            'tax_total_base' => 'string',
            'tax_claimable_total_base' => 'string',
            'total_base' => 'string',
            'amount_paid' => 'string',
            'amount_credited' => 'string',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * An approved document is immutable in every respect that matters.
     *
     * The carve-outs are the settlement bookkeeping and the void trail.
     * Everything else about an approved bill is history, and history that can
     * be edited is not evidence of anything.
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
                'approved_at',
                'approved_by',
                'voided_at',
                'voided_by',
                'due_date',
                'number',
                'updated_at',
            ];

            $forbidden = array_diff(array_keys($document->getDirty()), $permitted);

            if ($forbidden !== []) {
                throw new \RuntimeException(sprintf(
                    'Cannot change %s on approved %s %s. An approved document is corrected '.
                    'by a vendor credit or a void, never by editing — see '.
                    'ACCOUNTING_RULES.md §6.',
                    implode(', ', $forbidden),
                    $document->type->label(),
                    $document->number,
                ));
            }
        });

        self::deleting(function (self $document): void {
            if ($document->status->isIssued()) {
                throw new \RuntimeException(sprintf(
                    'Cannot delete %s %s once it has been %s. Void it instead, which posts '.
                    'a reversing entry and leaves both on the record.',
                    $document->type->label(),
                    $document->number,
                    $document->type === PurchaseDocumentType::PurchaseOrder ? 'sent' : 'approved',
                ));
            }
        });
    }

    /**
     * @return HasMany<PurchaseDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseDocumentLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<PurchaseDocumentLineTax, $this>
     */
    public function lineTaxes(): HasMany
    {
        return $this->hasMany(PurchaseDocumentLineTax::class);
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
     * What this document was converted from — a purchase order, for a bill.
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
     * The bill this vendor credit credits.
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
    public function vendorCredits(): HasMany
    {
        return $this->hasMany(self::class, 'credits_document_id');
    }

    /**
     * @return HasMany<PurchasePaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PurchasePaymentAllocation::class);
    }

    /**
     * What is still owed, in document currency.
     *
     * Never negative: paying a vendor more than the bill leaves an advance
     * held with them, not a negative bill.
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
     * The tax on this document that is NOT recoverable.
     *
     * Shown on the bill rather than left implicit, because it is a real cost
     * that a reader comparing two quotes has to see: a vendor whose tax we
     * cannot claim is more expensive than one whose we can, by exactly this
     * figure.
     */
    public function taxCapitalised(): string
    {
        return (string) BigDecimal::of($this->tax_total)
            ->minus(BigDecimal::of($this->tax_claimable_total))
            ->toScale(4);
    }

    /**
     * Whether this is past its due date and still owes something.
     *
     * Derived, never stored: "overdue" changes at midnight without anything
     * happening to the document.
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
    public function scopeOfType(Builder $query, PurchaseDocumentType $type): Builder
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
            PurchaseDocumentStatus::Open->value,
            PurchaseDocumentStatus::PartiallyPaid->value,
            PurchaseDocumentStatus::Overdue->value,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }
}
