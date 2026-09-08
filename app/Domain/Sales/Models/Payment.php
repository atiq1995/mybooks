<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Purchases\Models\PurchasePaymentAllocation;
use App\Domain\Tax\Models\Tax;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money arriving or leaving.
 *
 * Not "an invoice being paid": a payment may settle several documents, part
 * of one, or nothing at all. The unallocated remainder is an advance, and a
 * real liability — we owe goods, services or a refund.
 *
 * `amount` is what settles the contact's balance. `amount_received` is what
 * hit the bank. They differ by exactly the withholding, and keeping both is
 * what makes §4.2 expressible: the customer settles the invoice in full and
 * hands part of it to the tax authority on our behalf.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $direction
 * @property string $number
 * @property string $contact_id
 * @property Carbon $payment_date
 * @property string $bank_account_id
 * @property string $method
 * @property string|null $reference
 * @property string $currency
 * @property string $exchange_rate
 * @property string $amount
 * @property string $withholding_amount
 * @property string $amount_received
 * @property string $amount_base
 * @property string $withholding_amount_base
 * @property string|null $withholding_tax_id
 * @property string $allocated_amount
 * @property string $status
 * @property string|null $notes
 * @property string|null $journal_entry_id
 * @property string|null $void_journal_entry_id
 * @property Carbon|null $voided_at
 */
final class Payment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'payment_date',
        'bank_account_id',
        'method',
        'reference',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'exchange_rate' => 'string',
            'amount' => 'string',
            'withholding_amount' => 'string',
            'amount_received' => 'string',
            'amount_base' => 'string',
            'withholding_amount_base' => 'string',
            'allocated_amount' => 'string',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function withholdingTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'withholding_tax_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * What a payment MADE settled.
     *
     * A second relation rather than one polymorphic set, because the two
     * point at different tables — and a payment has a `direction`, so only
     * one of these is ever populated. Which one is not ambiguous: a receipt
     * has sales allocations, a payment has purchase ones.
     *
     * @return HasMany<PurchasePaymentAllocation, $this>
     */
    public function purchaseAllocations(): HasMany
    {
        return $this->hasMany(PurchasePaymentAllocation::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * What is left to apply to a document.
     *
     * This is the advance: money held that no invoice has claimed. It sits in
     * customer advances on the balance sheet until it is allocated, because
     * until then it is not revenue and not a reduction of receivables.
     */
    public function unallocatedAmount(): string
    {
        return (string) BigDecimal::of($this->amount)
            ->minus(BigDecimal::of($this->allocated_amount))
            ->toScale(4);
    }

    public function isFullyAllocated(): bool
    {
        return BigDecimal::of($this->unallocatedAmount())->isZero();
    }

    public function hasWithholding(): bool
    {
        return BigDecimal::of($this->withholding_amount)->isPositive();
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('direction', 'received');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMade(Builder $query): Builder
    {
        return $query->where('direction', 'made');
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }
}
