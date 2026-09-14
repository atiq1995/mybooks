<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Enums\BankAccountKind;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bank, cash or credit-card account, as banking sees it.
 *
 * The balance is NOT here. It is in the ledger account this row points at,
 * where every other balance in the system lives. A cached total on this table
 * would be a second answer to the same question, and the two would part
 * company the first time anything posted around it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $account_id
 * @property string $name
 * @property string|null $bank_name
 * @property string|null $account_number_masked
 * @property string|null $branch
 * @property BankAccountKind $kind
 * @property string $currency
 * @property bool $is_active
 * @property bool $is_primary
 * @property string|null $notes
 * @property Carbon|null $archived_at
 */
final class BankAccount extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'name',
        'bank_name',
        'account_number_masked',
        'branch',
        'kind',
        'currency',
        'is_active',
        'is_primary',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BankAccountKind::class,
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /**
     * @return HasMany<BankStatementImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
    }

    /**
     * @return HasMany<BankReconciliation, $this>
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class);
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    /**
     * How the account is named where there is room for one line only.
     *
     * The masked number is part of the name rather than a second column on
     * every screen, because "HBL Current" is ambiguous the moment a business
     * has two of them.
     */
    public function label(): string
    {
        return $this->account_number_masked === null
            ? $this->name
            : "{$this->name} ({$this->account_number_masked})";
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }
}
