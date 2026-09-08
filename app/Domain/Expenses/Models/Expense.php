<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Documents\Models\Attachment;
use App\Domain\Expenses\Enums\ExpensePaymentMode;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Sales\Models\SalesDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Money spent, and who spent it.
 *
 * Not a bill: an expense is usually paid at the moment it is recorded, so
 * there is no payable to age. Where it is not, the money is owed to a person
 * rather than a vendor — a different liability with a different report.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string|null $contact_id
 * @property string|null $merchant
 * @property Carbon $expense_date
 * @property ExpensePaymentMode $payment_mode
 * @property string|null $paid_through_account_id
 * @property string|null $reimburse_user_id
 * @property string|null $reference
 * @property ExpenseStatus $status
 * @property string $currency
 * @property string $exchange_rate
 * @property bool $prices_include_tax
 * @property string $subtotal
 * @property string $tax_total
 * @property string $tax_claimable_total
 * @property string $total
 * @property string $subtotal_base
 * @property string $tax_total_base
 * @property string $tax_claimable_total_base
 * @property string $total_base
 * @property bool $is_billable
 * @property string|null $billable_contact_id
 * @property string|null $billed_document_id
 * @property string|null $notes
 * @property string|null $journal_entry_id
 * @property string|null $void_journal_entry_id
 * @property Carbon|null $submitted_at
 * @property string|null $submitted_by
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|null $voided_at
 */
final class Expense extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    /**
     * Only what a user may edit before it is approved.
     */
    protected $fillable = [
        'merchant',
        'expense_date',
        'reference',
        'notes',
        'prices_include_tax',
        'is_billable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'payment_mode' => ExpensePaymentMode::class,
            'expense_date' => 'date',
            'prices_include_tax' => 'boolean',
            'is_billable' => 'boolean',
            // Decimal strings throughout.
            'exchange_rate' => 'string',
            'subtotal' => 'string',
            'tax_total' => 'string',
            'tax_claimable_total' => 'string',
            'total' => 'string',
            'subtotal_base' => 'string',
            'tax_total_base' => 'string',
            'tax_claimable_total_base' => 'string',
            'total_base' => 'string',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * An approved expense is immutable in every respect that matters.
     *
     * The carve-outs are the workflow trail and the billing link. Everything
     * else about an approved expense is history, and history that can be
     * edited is not evidence of anything.
     */
    protected static function booted(): void
    {
        self::updating(function (self $expense): void {
            $wasApproved = $expense->getOriginal('status') === ExpenseStatus::Approved->value;

            if (! $wasApproved) {
                return;
            }

            $permitted = [
                'status',
                'journal_entry_id',
                'void_journal_entry_id',
                'billed_document_id',
                'voided_at',
                'voided_by',
                'approved_at',
                'approved_by',
                'updated_at',
            ];

            $forbidden = array_diff(array_keys($expense->getDirty()), $permitted);

            if ($forbidden !== []) {
                throw new \RuntimeException(sprintf(
                    'Cannot change %s on approved expense %s. An approved expense is '.
                    'corrected by voiding it and recording it again — see '.
                    'ACCOUNTING_RULES.md §6.',
                    implode(', ', $forbidden),
                    $expense->number,
                ));
            }
        });

        self::deleting(function (self $expense): void {
            if (! $expense->status->isDeletable()) {
                throw new \RuntimeException(sprintf(
                    'Cannot delete expense %s: it has been approved and posted. Void it '.
                    'instead, which posts a reversing entry and leaves both on the record.',
                    $expense->number,
                ));
            }
        });
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<ExpenseLineTax, $this>
     */
    public function lineTaxes(): HasMany
    {
        return $this->hasMany(ExpenseLineTax::class);
    }

    /**
     * The receipts.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Who this will be rebilled to, if it is billable.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function billableContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'billable_contact_id');
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function billedDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'billed_document_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function paidThroughAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_through_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reimburseUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reimburse_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
     * The tax on this expense that is NOT recoverable.
     *
     * Shown rather than left implicit, because on expenses it is often the
     * whole of it — entertainment, staff welfare, a car — and it is a real
     * cost that somebody reviewing the claim needs to see.
     */
    public function taxCapitalised(): string
    {
        return (string) BigDecimal::of($this->tax_total)
            ->minus(BigDecimal::of($this->tax_claimable_total))
            ->toScale(4);
    }

    /**
     * Whether this can still be rebilled to a customer.
     *
     * Approved, billable, named a customer, and not already on an invoice.
     * All four, because each one on its own would put an expense on the
     * "to bill" list that cannot actually be billed.
     */
    public function isAwaitingRebill(): bool
    {
        return $this->status === ExpenseStatus::Approved
            && $this->is_billable
            && $this->billable_contact_id !== null
            && $this->billed_document_id === null;
    }

    public function hasReceipt(): bool
    {
        return $this->attachments()->exists();
    }

    public function isForeignCurrency(): bool
    {
        return ! BigDecimal::of($this->exchange_rate)->isEqualTo(BigDecimal::one());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Submitted->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingRebill(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Approved->value)
            ->where('is_billable', true)
            ->whereNotNull('billable_contact_id')
            ->whereNull('billed_document_id');
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }
}
