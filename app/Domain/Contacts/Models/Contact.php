<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Sales\Models\SalesDocument;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer, a vendor, or both.
 *
 * @property string $id
 * @property string $organization_id
 * @property ContactKind $kind
 * @property string $display_name
 * @property string|null $legal_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $tax_registration_number
 * @property string|null $sales_tax_registration_number
 * @property bool $is_tax_filer
 * @property string|null $currency
 * @property int $payment_terms_days
 * @property string|null $credit_limit
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $shipping_address
 * @property string|null $notes
 * @property string|null $receivable_account_id
 * @property string|null $payable_account_id
 * @property bool $is_active
 * @property Carbon|null $archived_at
 */
final class Contact extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'kind',
        'display_name',
        'legal_name',
        'email',
        'phone',
        'website',
        'tax_registration_number',
        'sales_tax_registration_number',
        'is_tax_filer',
        'currency',
        'payment_terms_days',
        'credit_limit',
        'billing_address',
        'shipping_address',
        'notes',
        'receivable_account_id',
        'payable_account_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContactKind::class,
            'is_tax_filer' => 'boolean',
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
            'credit_limit' => 'string',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ContactPerson, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(ContactPerson::class);
    }

    /**
     * @return HasMany<SalesDocument, $this>
     */
    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    /**
     * The receivable control account for this contact.
     *
     * Almost always the system account. An override exists for the rare
     * organisation keeping receivables split by segment, and resolving it
     * here means no posting rule has to know about that case.
     */
    public function receivableAccountId(): string
    {
        if ($this->receivable_account_id !== null) {
            return $this->receivable_account_id;
        }

        return Account::query()
            ->where('system_role', SystemAccount::AccountsReceivable->value)
            ->sole()
            ->id;
    }

    /**
     * When an invoice issued today would fall due.
     *
     * Terms of zero means on receipt, which is the same date — not the next
     * day.
     */
    public function dueDateFrom(Carbon $issuedOn): Carbon
    {
        return $issuedOn->copy()->addDays($this->payment_terms_days);
    }

    /**
     * What this contact currently owes, from issued and unvoided invoices
     * less credits and payments.
     *
     * Summed from the documents rather than cached on the row: a cached
     * balance is a second source of truth, and the first time it drifts from
     * the documents nobody can tell which is wrong.
     */
    public function outstandingBalance(): string
    {
        /** @var object{owed: string|null}|null $row */
        $row = $this->salesDocuments()
            ->selectRaw('COALESCE(SUM(total - amount_paid - amount_credited), 0) AS owed')
            ->where('type', 'invoice')
            ->whereIn('status', ['sent', 'open', 'partially_paid', 'overdue'])
            ->first();

        return (string) BigDecimal::of((string) ($row->owed ?? '0'))->toScale(4);
    }

    public function isOverCreditLimit(): bool
    {
        if ($this->credit_limit === null) {
            return false;
        }

        return BigDecimal::of($this->outstandingBalance())
            ->isGreaterThan(BigDecimal::of($this->credit_limit));
    }

    public function acceptsDocuments(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->whereIn('kind', [ContactKind::Customer->value, ContactKind::Both->value]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVendors(Builder $query): Builder
    {
        return $query->whereIn('kind', [ContactKind::Vendor->value, ContactKind::Both->value]);
    }
}
